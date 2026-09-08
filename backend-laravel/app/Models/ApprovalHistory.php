<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ApprovalHistory — maps to the `approval_histories` table (added M4).
 *
 * Append-only record of approval-workflow transitions (notified/approved/rejected)
 * referenced by M11. Each row captures an action of a month_approval.
 */
class ApprovalHistory extends Model
{
    protected $table = 'approval_histories';

    const UPDATED_AT = null; // append-only: Eloquent manages created_at only

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'approval_id'   => 'integer',
            'actor_user_id' => 'integer',
            'created_at'    => 'datetime',
        ];
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(MonthApproval::class, 'approval_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}