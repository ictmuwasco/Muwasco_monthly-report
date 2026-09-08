<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Role — maps to the live `roles` table.
 *
 * In the live schema `roles` is reference data (admin, technical_manager, ...).
 * Users are NOT FK-linked to it (the live `users` table uses a `role` enum instead),
 * so Role has no users() relation — keep it as a catalog for dropdowns/display.
 */
class Role extends Model
{
    protected $table = 'roles';

    /**
     * Live `roles` has only `created_at` (no updated_at column).
     */
    const UPDATED_AT = null;

    /** @var array<int, string> */
    protected $guarded = [];

    /** @var array<int, string> */
    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
