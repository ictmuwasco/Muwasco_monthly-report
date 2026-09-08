<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Parameter — maps to the live `parameters` table.
 */
class Parameter extends Model
{
    protected $table = 'parameters';

    public $timestamps = false;

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'required'    => 'boolean',
        ];
    }

    /* ── Relationships ───────────────────────────────────── */

    public function category(): BelongsTo
    {
        // NB: no DB-level FK on parameters.category_id in live; it is an implicit link.
        return $this->belongsTo(ParameterCategory::class, 'category_id');
    }

    public function monthlyData(): HasMany
    {
        return $this->hasMany(MonthlyData::class, 'parameter_id');
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_parameter_assignments',
            'parameter_id', 'user_id')->withPivot('assigned_at');
    }
}
