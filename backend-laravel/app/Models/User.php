<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

/**
 * User — maps to the live `users` table.
 *
 * Live schema (authoritative): uses `role` enum('admin','user') for authorization
 * (NOT a role_id FK), plus `full_name`, `is_active`. There is no `remember_token`
 * or `email_verified_at` column in the live table.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Live `users` has only `created_at` (no updated_at column).
     */
    const UPDATED_AT = null;

    /**
     * Mass-assignable attributes (never allow role/is_active via generic update
     * without policy checks — handled in UpdateUserRequest + UserPolicy).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username',
        'email',
        'full_name',
        'password',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /* ── Helpers ─────────────────────────────────────────── */

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /* ── Relationships ───────────────────────────────────── */

    /**
     * Parameters this user can access (pivot user_parameter_assignments).
     */
    public function accessibleParameters(): BelongsToMany
    {
        return $this->belongsToMany(Parameter::class, 'user_parameter_assignments',
            'user_id', 'parameter_id')->withPivot('assigned_at');
    }

    /**
     * Categories (sections) this user can access (pivot user_section_assignments).
     */
    public function accessibleCategories(): BelongsToMany
    {
        return $this->belongsToMany(ParameterCategory::class, 'user_section_assignments',
            'user_id', 'category_id')->withPivot('assigned_at');
    }

    /**
     * Monthly data rows entered (not directly owned by a single user in live schema,
     * but useful for audit/analytics).
     */
    public function monthlyData(): HasMany
    {
        return $this->hasMany(MonthlyData::class);
    }
}
