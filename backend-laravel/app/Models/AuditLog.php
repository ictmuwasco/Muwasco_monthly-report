<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AuditLog — maps to the `audit_logs` table (added M4).
 *
 * Append-only application audit trail. There are no update/delete endpoints for
 * ordinary users; only the audit service appends records here.
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    const UPDATED_AT = null; // append-only: Eloquent manages created_at, never updated_at

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'actor_user_id' => 'integer',
            'entity_id'     => 'integer',
            'old_values'    => 'array',
            'new_values'    => 'array',
            'created_at'    => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}