<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for parameter-category management.
 * Mirrors ParameterPolicy — read is open to all authenticated users,
 * write is admin-only.
 */
class ParameterCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isAdmin();
    }
}