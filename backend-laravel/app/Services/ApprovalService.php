<?php

namespace App\Services;

use App\Mail\ApprovalRequested;
use App\Mail\ChangesRequested;
use App\Mail\ReviewApproved;
use App\Models\ApprovalHistory;
use App\Models\MonthApproval;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ApprovalService — the reporting-period approval workflow (M11).
 *
 * Flow (aligned with the M9 period state machine):
 *   1. Admin requests review for a manager role once the period is 'submitted'
 *      → the period moves to 'under_review' and an approval row is created
 *        (status=notified) with a secure email token (7-day expiry).
 *   2. The manager decides via the emailed token link (public, no login) or an
 *      admin records the decision on their behalf (approved_by_user_id set).
 *   3. When EVERY approval row for the period is approved → period 'approved'.
 *      When ANY is rejected   → period 'changes_requested' (corrections needed;
 *      the resubmission path is handled by the M10 submit action).
 *
 * Every action is written to the append-only `approval_histories` trail.
 */
class ApprovalService
{
    public const TOKEN_DAYS = 7;

    public const MANAGER_ROLES = ['technical_manager', 'commercial_manager'];

    /* ── Request review ──────────────────────────────────── */

    /**
     * Admin requests a manager's review of a submitted period.
     *
     * @return array{approval: MonthApproval, emailed_to: array<int, string>}
     */
    public function requestReview(User $admin, ReportingPeriod $period, string $managerRole, ?string $notifyEmail = null): array
    {
        if (! in_array($managerRole, self::MANAGER_ROLES, true)) {
            throw ValidationException::withMessages([
                'manager_role' => 'manager_role must be one of: '.implode(', ', self::MANAGER_ROLES).'.',
            ]);
        }

        if (! in_array($period->status, [ReportingPeriod::STATUS_SUBMITTED, ReportingPeriod::STATUS_UNDER_REVIEW], true)) {
            throw ValidationException::withMessages([
                'period' => "Reviews can only be requested for submitted periods (current: {$period->status}).",
            ]);
        }

        if ($period->approvals()->where('manager_role', $managerRole)->exists()) {
            throw ValidationException::withMessages([
                'manager_role' => "A {$managerRole} review has already been requested for this period.",
            ]);
        }

        $previous = $period->status;
        $rawToken = Str::random(40);

        $approval = DB::transaction(function () use ($admin, $period, $managerRole, $previous, $rawToken) {
            if ($previous === ReportingPeriod::STATUS_SUBMITTED) {
                $period->transitionTo(ReportingPeriod::STATUS_UNDER_REVIEW);
            }

            $approval = MonthApproval::create([
                'month_id'         => $period->id,
                'manager_role'     => $managerRole,
                'status'           => 'notified',
                'notified_at'      => now(),
                'notified_by'      => $admin->id,
                'approval_token'   => hash('sha256', $rawToken),
                'token_expires_at' => now()->addDays(self::TOKEN_DAYS),
            ]);

            ApprovalHistory::create([
                'approval_id'   => $approval->id,
                'action'        => 'notified',
                'prev_status'   => $previous,
                'new_status'    => $period->fresh()->status,
                'actor_user_id' => $admin->id,
                'comment'       => "Review requested from {$managerRole}.",
                'created_at'    => now(),
            ]);

            return $approval;
        });

        $emailedTo = $this->sendRequestEmail($period, $approval, $rawToken, $notifyEmail);

        return ['approval' => $approval, 'raw_token' => $rawToken, 'emailed_to' => $emailedTo];
    }

    /* ── Decide ──────────────────────────────────────────── */

    /**
     * Record a decision on an approval.
     *
     * @param  User|null  $actor  Authenticated admin, or null for token decisions.
     */
    public function decide(MonthApproval $approval, string $action, ?string $comment, ?User $actor): array
    {
        $period = $approval->month;

        if ($period->isClosed()) {
            throw ValidationException::withMessages([
                'period' => 'This reporting period is closed; decisions are no longer accepted.',
            ]);
        }

        if ($approval->status !== 'notified') {
            throw ValidationException::withMessages([
                'status' => "This review was already {$approval->status}.",
            ]);
        }

        if (! in_array($action, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages([
                'action' => "action must be 'approve' or 'reject'.",
            ]);
        }

        if ($action === 'reject' && ($comment === null || trim($comment) === '')) {
            throw ValidationException::withMessages([
                'comment' => 'A rejection reason is required.',
            ]);
        }

        $previous = $approval->status;

        DB::transaction(function () use ($approval, $action, $comment, $actor, $period, $previous) {
            $approval->update([
                'status'              => $action === 'approve' ? 'approved' : 'rejected',
                'approved_at'         => now(),
                'approved_by_user_id' => $actor?->id,
                'rejection_reason'    => $action === 'reject' ? $comment : null,
            ]);

            ApprovalHistory::create([
                'approval_id'   => $approval->id,
                'action'        => $action === 'approve' ? 'approved' : 'rejected',
                'prev_status'   => $previous,
                'new_status'    => $approval->status,
                'actor_user_id' => $actor?->id,
                'comment'       => $comment,
                'created_at'    => now(),
            ]);

            $this->refreshPeriodStatus($period);
        });

        AuditLogger::record('approval.decided', $period, ['status' => $previous], [
            'approval_id' => $approval->id,
            'manager_role' => $approval->manager_role,
            'decision'     => $approval->status,
            'via'          => $actor ? 'admin' : 'token',
        ]);

        // Notify the admin who requested the review (queued), falling back to
        // active admins when the requester is unknown.
        $this->notifyDecision($approval, $period);

        return ['approval' => $approval->fresh(), 'period' => $period->fresh()];
    }

    /* ── Token resolution ────────────────────────────────── */

    /**
     * Resolve an approval by its raw token (secure comparison, expiry checked).
     */
    public function resolveByToken(string $rawToken): MonthApproval
    {
        $approval = MonthApproval::where('approval_token', hash('sha256', $rawToken))->first();

        if (! $approval) {
            abort(404, 'Approval request not found.');
        }

        if ($approval->token_expires_at && $approval->token_expires_at->isPast()) {
            abort(410, 'This approval link has expired.');
        }

        return $approval;
    }

    /* ── Internals ───────────────────────────────────────── */

    /**
     * Notify the admin who requested the review of the decision outcome.
     * Falls back to active admins when the requester is unknown.
     */
    private function notifyDecision(MonthApproval $approval, ReportingPeriod $period): void
    {
        $recipients = collect();

        if ($approval->notifiedBy) {
            $recipients = collect([$approval->notifiedBy]);
        } else {
            $recipients = User::query()->where('role', 'admin')->where('is_active', true)->get();
        }

        foreach ($recipients as $user) {
            Mail::to($user->email)->send(
                $approval->status === 'approved'
                    ? new ReviewApproved($period, $approval)
                    : new ChangesRequested($period, $approval)
            );
        }
    }

    /**
     * Recompute the period status from its approval rows:
     * any rejection → changes_requested; all approved → approved;
     * otherwise remain under_review.
     */
    private function refreshPeriodStatus(ReportingPeriod $period): void
    {
        if ($period->isClosed()) {
            return;
        }

        $approvals = $period->approvals()->get();

        if ($approvals->isEmpty() || $approvals->contains(fn ($a) => $a->status !== 'approved')) {
            if ($approvals->contains(fn ($a) => $a->status === 'rejected')
                && $period->status === ReportingPeriod::STATUS_UNDER_REVIEW) {
                $period->transitionTo(ReportingPeriod::STATUS_CHANGES_REQUESTED);
            }

            return;
        }

        if ($period->status === ReportingPeriod::STATUS_UNDER_REVIEW) {
            $period->transitionTo(ReportingPeriod::STATUS_APPROVED);
        }
    }

    /**
     * Email the review request. Recipients: explicit notify_email (admin override)
     * or active users carrying the manager role (none in the live schema yet —
     * the live users table only has admin/user roles, so explicit email is the
     * practical path until manager accounts exist).
     *
     * @return array<int, string>
     */
    private function sendRequestEmail(ReportingPeriod $period, MonthApproval $approval, string $rawToken, ?string $notifyEmail): array
    {
        if ($notifyEmail !== null && $notifyEmail !== '') {
            Mail::to($notifyEmail)->send(new ApprovalRequested($period, $approval, $rawToken));

            return [$notifyEmail];
        }

        $recipients = User::query()
            ->where('is_active', true)
            ->whereIn('role', [$approval->manager_role]) // live role enum has no manager values yet
            ->pluck('email')
            ->all();

        foreach ($recipients as $email) {
            Mail::to($email)->send(new ApprovalRequested($period, $approval, $rawToken));
        }

        return $recipients;
    }
}
