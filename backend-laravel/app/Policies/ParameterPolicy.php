<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for parameter / parameter-category management.
 *
 * Read access (list, show) is allowed to any authenticated user — parameters
 * and categories are reference data used throughout the app.
 *
 * Write access (create/update) is restricted to admins only. The policy is the
 * security boundary; frontend hiding of controls is a UX convenience only.
 */
class ParameterPolicy
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