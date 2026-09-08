<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ParameterCategory — maps to the live `parameter_categories` table.
 */
class ParameterCategory extends Model
{
    protected $table = 'parameter_categories';

    public $timestamps = false;

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
        ];
    }

    /* ── Relationships ───────────────────────────────────── */

    public function parameters(): HasMany
    {
        return $this->hasMany(Parameter::class, 'category_id');
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_section_assignments',
            'category_id', 'user_id')->withPivot('assigned_at');
    }
}
