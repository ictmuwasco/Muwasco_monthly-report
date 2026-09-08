<?php

namespace Tests\Feature;

use App\Mail\ApprovalRequested;
use App\Models\MonthApproval;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\UsesSqliteLegacySchema;
use Tests\TestCase;

/**
 * Approval workflow tests (M11).
 * In-memory SQLite only — the live MySQL database is NEVER touched.
 */
class ApprovalTest extends TestCase
{
    use UsesSqliteLegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSqliteSchema();

        Mail::fake();
    }

    private ?User $adminUser = null;

    private function admin(): User
    {
        return $this->adminUser ??= User::create([
            'username' => 'admin1', 'email' => 'admin1@example.com',
            'full_name' => 'Admin One', 'password' => Hash::make('secret123'),
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function officer(): User
    {
        return User::create([
            'username' => 'officer'.uniqid(), 'email' => 'officer'.uniqid().'@example.com',
            'full_name' => 'Officer', 'password' => Hash::make('secret123'),
            'role' => 'user', 'is_active' => true,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function submittedPeriod(): ReportingPeriod
    {
        return ReportingPeriod::create([
            'name' => 'September 2025', 'month_year' => '2025-09-01',
            'start_date' => '2025-09-01', 'end_date' => '2025-09-30',
            'status' => 'submitted',
        ]);
    }

    private function requestReview(ReportingPeriod $period, string $role = 'technical_manager', ?string $email = 'manager@example.com'): array
    {
        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/approvals", [
                'manager_role' => $role, 'notify_email' => $email,
            ]);

        $res->assertCreated();

        return $res->json();
    }

    /* ── Request review ──────────────────────────────────── */

    public function test_admin_can_request_review_of_submitted_period(): void
    {
        $period = $this->submittedPeriod();

        $json = $this->requestReview($period);

        $this->assertSame('under_review', $json['period']['status']);
        $this->assertSame('notified', $json['approval']['status']);
        $this->assertSame(['manager@example.com'], $json['emailed_to']);
        $this->assertArrayNotHasKey('approval_token', $json['approval']); // token never exposed in JSON

        $this->assertDatabaseHas('month_approvals', [
            'month_id' => $period->id, 'manager_role' => 'technical_manager', 'status' => 'notified',
        ]);

        Mail::assertQueued(ApprovalRequested::class, 1);
    }

    public function test_duplicate_review_request_is_rejected(): void
    {
        $period = $this->submittedPeriod();
        $this->requestReview($period);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/approvals",
                ['manager_role' => 'technical_manager'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manager_role');
    }

    public function test_review_request_requires_submitted_period(): void
    {
        $period = ReportingPeriod::create(['month_year' => '2025-09-01', 'status' => 'open']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/approvals",
                ['manager_role' => 'technical_manager'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('period');
    }

    public function test_review_request_validates_manager_role(): void
    {
        $period = $this->submittedPeriod();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/approvals",
                ['manager_role' => 'ceo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manager_role');
    }

    public function test_non_admin_cannot_request_review(): void
    {
        $period = $this->submittedPeriod();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->officer()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/approvals",
                ['manager_role' => 'technical_manager'])
            ->assertForbidden();
    }

    public function test_second_reviewer_can_be_added_while_under_review(): void
    {
        $period = $this->submittedPeriod();
        $this->requestReview($period, 'technical_manager');

        $json = $this->requestReview($period, 'commercial_manager');

        $this->assertSame('under_review', $json['period']['status']);
        $this->assertSame(2, MonthApproval::where('month_id', $period->id)->count());
    }

    /* ── Admin-recorded decisions ────────────────────────── */

    public function test_admin_can_record_approval_and_period_becomes_approved(): void
    {
        $period = $this->submittedPeriod();
        $this->requestReview($period, 'technical_manager', null); // no email

        $approval = MonthApproval::where('month_id', $period->id)->first();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/approvals/{$approval->id}/decide",
                ['action' => 'approve', 'comment' => 'Looks good'])
            ->assertOk()
            ->assertJsonPath('approval.status', 'approved')
            ->assertJsonPath('period.status', 'approved'); // single approval → all approved

        $this->assertDatabaseHas('approval_histories', [
            'approval_id' => $approval->id, 'action' => 'approved', 'actor_user_id' => $this->admin()->id,
        ]);
    }

    public function test_admin_can_record_rejection_and_period_needs_changes(): void
    {
        $period = $this->submittedPeriod();
        $this->requestReview($period, 'technical_manager', null);

        $approval = MonthApproval::where('month_id', $period->id)->first();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/approvals/{$approval->id}/decide",
                ['action' => 'reject', 'comment' => 'NRW figure looks wrong'])
            ->assertOk()
            ->assertJsonPath('approval.status', 'rejected')
            ->assertJsonPath('period.status', 'changes_requested');

        $this->assertDatabaseHas('month_approvals', [
            'id' => $approval->id, 'rejection_reason' => 'NRW figure looks wrong',
        ]);
    }

    public function test_rejection_requires_comment(): void
    {
        $period = $this->submittedPeriod();
        $this->requestReview($period, 'technical_manager', null);

        $approval = MonthApproval::where('month_id', $period->id)->first();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/approvals/{$approval->id}/decide",
                ['action' => 'reject'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('comment');
    }

    public function test_cannot_decide_twice(): void
    {
        $period = $this->submittedPeriod();
        $this->requestReview($period, 'technical_manager', null);
        $approval = MonthApproval::where('month_id', $period->id)->first();

        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($this->admin())];
        $url = "/api/v1/reporting-periods/{$period->id}/approvals/{$approval->id}/decide";

        $this->withHeaders($headers)->postJson($url, ['action' => 'approve'])->assertOk();
        $this->withHeaders($headers)->postJson($url,
            ['action' => 'reject', 'comment' => 'second try'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_cannot_decide_on_closed_period(): void
    {
        $period = $this->submittedPeriod();
        $this->requestReview($period, 'technical_manager', null);
        $period->transitionTo('closed');

        $approval = MonthApproval::where('month_id', $period->id)->first();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/approvals/{$approval->id}/decide",
                ['action' => 'approve'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('period');
    }

    public function test_cross_period_approval_id_is_rejected(): void
    {
        $period = $this->submittedPeriod();
        $this->requestReview($period, 'technical_manager', null);

        // A different period (unique month_year) with no approvals of its own.
        $other = ReportingPeriod::create([
            'name' => 'October 2025', 'month_year' => '2025-10-01',
            'start_date' => '2025-10-01', 'end_date' => '2025-10-31',
            'status' => 'submitted',
        ]);

        $approval = MonthApproval::where('month_id', $period->id)->first();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$other->id}/approvals/{$approval->id}/decide",
                ['action' => 'approve'])
            ->assertNotFound();
    }

    /* ── Emailed token flow ──────────────────────────────── */

    public function test_manager_can_approve_via_email_token(): void
    {
        $period = $this->submittedPeriod();
        $json = $this->requestReview($period, 'technical_manager');
        $rawToken = $this->extractToken($json['approval']['id']);

        // Public preview (no auth header).
        $this->getJson("/api/v1/approval-requests/{$rawToken}")
            ->assertOk()
            ->assertJsonPath('status', 'notified')
            ->assertJsonPath('manager_role', 'technical_manager');

        $this->postJson("/api/v1/approval-requests/{$rawToken}/decide",
            ['action' => 'approve', 'comment' => 'Verified against source data'])
            ->assertOk()
            ->assertJsonPath('period.status', 'approved');

        $this->assertDatabaseHas('month_approvals', [
            'id' => $json['approval']['id'], 'status' => 'approved', 'approved_by_user_id' => null,
        ]);
    }

    public function test_manager_can_reject_via_email_token(): void
    {
        $period = $this->submittedPeriod();
        $json = $this->requestReview($period, 'commercial_manager');
        $rawToken = $this->extractToken($json['approval']['id']);

        $this->postJson("/api/v1/approval-requests/{$rawToken}/decide",
            ['action' => 'reject', 'comment' => 'Revenue totals do not match'])
            ->assertOk()
            ->assertJsonPath('period.status', 'changes_requested');
    }

    public function test_token_can_only_be_used_once(): void
    {
        $period = $this->submittedPeriod();
        $json = $this->requestReview($period, 'technical_manager');
        $rawToken = $this->extractToken($json['approval']['id']);

        $this->postJson("/api/v1/approval-requests/{$rawToken}/decide", ['action' => 'approve'])->assertOk();
        $this->postJson("/api/v1/approval-requests/{$rawToken}/decide",
            ['action' => 'reject', 'comment' => 'again'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_expired_token_is_rejected_with_410(): void
    {
        $period = $this->submittedPeriod();
        $json = $this->requestReview($period, 'technical_manager');
        $rawToken = $this->extractToken($json['approval']['id']);

        MonthApproval::where('id', $json['approval']['id'])
            ->update(['token_expires_at' => now()->subDay()]);

        $this->getJson("/api/v1/approval-requests/{$rawToken}")->assertStatus(410);
    }

    public function test_unknown_token_is_404(): void
    {
        $this->getJson('/api/v1/approval-requests/not-a-real-token')->assertNotFound();
    }

    /**
     * The raw token is never stored in plain text; tests recover it from the
     * mailable exactly as the emailed manager would receive it. The mailable
     * is queued (M12), so it is read from the queued collection.
     */
    private function extractToken(int $approvalId): string
    {
        $mailable = Mail::queued(ApprovalRequested::class)->first(
            fn (ApprovalRequested $m) => $m->approval->id === $approvalId
        );

        $this->assertNotNull($mailable, 'ApprovalRequested mailable was not queued.');

        return $mailable->rawToken;
    }
}
