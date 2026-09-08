<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * UserSectionAssignment — pivot table `user_section_assignments`.
 *
 * Grants a user access to a category (section). UNIQUE(user_id, category_id) is
 * enforced by the `unique_user_category` key in the live DB.
 */
class UserSectionAssignment extends Pivot
{
    protected $table = 'user_section_assignments';

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

    public function category(): BelongsTo
    {
        return $this->belongsTo(ParameterCategory::class, 'category_id');
    }
}
