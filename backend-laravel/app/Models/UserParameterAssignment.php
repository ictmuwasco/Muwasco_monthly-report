<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * UserParameterAssignment — pivot table `user_parameter_assignments`.
 *
 * Grants a user access to a parameter. UNIQUE(user_id, parameter_id) is enforced
 * by the `unique_user_parameter` key in the live DB.
 */
class UserParameterAssignment extends Pivot
{
    protected $table = 'user_parameter_assignments';

    public $incrementing = true;

    public $timestamps = false;

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(Parameter::class, 'parameter_id');
    }
}
