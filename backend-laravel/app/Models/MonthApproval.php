<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MonthApproval — maps to the live `month_approvals` table (added M2).
 *
 * Records the technical/commercial manager approval of a reporting period.
 * UNIQUE(month_id, manager_role) allows exactly one approval row per (period, manager).
 */
class MonthApproval extends Model
{
    protected $table = 'month_approvals';

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'month_id'         => 'integer',
            'notified_by'      => 'integer',
            'approved_by_user_id' => 'integer',
            'notified_at'      => 'datetime',
            'approved_at'      => 'datetime',
            'token_expires_at' => 'datetime',
        ];
    }

    public function month(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'month_id');
    }

    public function notifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notified_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Append-only decision trail for this approval.
     */
    public function histories(): HasMany
    {
        return $this->hasMany(ApprovalHistory::class, 'approval_id');
    }
}
