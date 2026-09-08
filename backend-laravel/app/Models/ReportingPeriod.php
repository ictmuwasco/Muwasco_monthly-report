<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * ReportingPeriod — maps to the live `months` table (a monthly reporting window).
 *
 * Status lifecycle (enum widened additively in M9; legacy 'draft'/'submitted'
 * values are preserved and remain valid):
 *
 *   draft ──▶ open ──▶ (submitted via M10 data-entry) ──▶ under_review ──▶ approved ──▶ closed
 *     │         │                                          │        ▲
 *     │         └──────────────────────────────────────────┴─ rejected/changes_requested
 *     └──────────────────────▶ closed (any state may be force-closed by admin)
 *
 * Transitions are enforced server-side in allowedTransitions(); 'submitted'
 * is set by the monthly-data submit action (M10), approval states by M11.
 */
class ReportingPeriod extends Model
{
    public const STATUS_DRAFT             = 'draft';
    public const STATUS_OPEN              = 'open';
    public const STATUS_SUBMITTED         = 'submitted';
    public const STATUS_UNDER_REVIEW      = 'under_review';
    public const STATUS_CHANGES_REQUESTED = 'changes_requested';
    public const STATUS_APPROVED          = 'approved';
    public const STATUS_REJECTED          = 'rejected';
    public const STATUS_CLOSED            = 'closed';

    protected $table = 'months';

    protected $primaryKey = 'id';

    /**
     * Live `months` has only `created_at` (no updated_at column).
     */
    const UPDATED_AT = null;

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // 'date:Y-m-d' serializes plain YYYY-MM-DD (clean for reporting UIs).
            'start_date'          => 'date:Y-m-d',
            'end_date'            => 'date:Y-m-d',
            'submission_deadline' => 'date:Y-m-d',
            'created_at'          => 'datetime',
        ];
    }

    /* ── Status lifecycle ────────────────────────────────── */

    /**
     * All valid status values (mirrors the widened DB enum).
     *
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_OPEN,
            self::STATUS_UNDER_REVIEW, self::STATUS_CHANGES_REQUESTED,
            self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CLOSED,
        ];
    }

    /**
     * Server-enforced transition map. 'submitted' is normally set by the
     * data-entry submit action (M10), not by generic status updates, but the
     * admin transition endpoint may also move open → submitted explicitly.
     *
     * @return array<string, array<int, string>>
     */
    public static function allowedTransitions(): array
    {
        return [
            self::STATUS_DRAFT             => [self::STATUS_OPEN, self::STATUS_CLOSED],
            self::STATUS_OPEN              => [self::STATUS_SUBMITTED, self::STATUS_CLOSED],
            self::STATUS_SUBMITTED         => [self::STATUS_UNDER_REVIEW, self::STATUS_CLOSED],
            self::STATUS_UNDER_REVIEW      => [self::STATUS_APPROVED, self::STATUS_REJECTED,
                                                self::STATUS_CHANGES_REQUESTED, self::STATUS_CLOSED],
            self::STATUS_CHANGES_REQUESTED => [self::STATUS_OPEN, self::STATUS_SUBMITTED, self::STATUS_CLOSED],
            self::STATUS_APPROVED          => [self::STATUS_CLOSED],
            self::STATUS_REJECTED          => [self::STATUS_OPEN, self::STATUS_CLOSED],
            self::STATUS_CLOSED            => [self::STATUS_OPEN], // admin reopen
        ];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::allowedTransitions()[$this->status] ?? [], true);
    }

    public function transitionTo(string $status): void
    {
        if (! in_array($status, self::statuses(), true)) {
            throw new InvalidArgumentException("Unknown status [{$status}].");
        }

        if (! $this->canTransitionTo($status)) {
            throw new InvalidArgumentException(
                "Invalid status transition [{$this->status} → {$status}]."
            );
        }

        $this->forceFill(['status' => $status])->save();
    }

    /* ── State helpers ───────────────────────────────────── */

    public function isOpenForEntry(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_OPEN, self::STATUS_CHANGES_REQUESTED], true);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isLockedForEditing(): bool
    {
        return in_array($this->status, [self::STATUS_CLOSED, self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED], true);
    }

    public function isPastDeadline(): bool
    {
        return $this->submission_deadline !== null && $this->submission_deadline->isPast();
    }

    /* ── Scopes ──────────────────────────────────────────── */

    public function scopeOrderByRecent($query)
    {
        return $query->orderByDesc('start_date');
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /* ── Relationships ───────────────────────────────────── */

    public function monthlyData(): HasMany
    {
        return $this->hasMany(MonthlyData::class, 'month_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(MonthApproval::class, 'month_id');
    }
}

