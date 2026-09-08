<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for user management.
 *
 * Only admins may manage users. A user may always view their own record.
 * Frontend hiding of controls is a UX convenience only — this policy is the
 * security boundary (enforced in every user-management endpoint).
 */
class UserPolicy
{
    /**
     * Anyone authenticated may view a list of users (needed for approval
     * pickers, data-entry attribution, etc.).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * A user may view their own record; admins may view any.
     */
    public function view(User $user, User $target): bool
    {
        return $user->isAdmin() || $user->id === $target->id;
    }

    /**
     * Only admins may create users.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Only admins may update users.
     */
    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    /**
     * Only admins may delete/deactivate users.
     * An admin cannot deactivate or delete their own account (prevents
     * locking everyone out of the system).
     */
    public function delete(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->id !== $target->id;
    }

    /**
     * Only admins may reset another user's password.
     */
    public function resetPassword(User $user, User $target): bool
    {
        return $user->isAdmin() || $user->id === $target->id;
    }
}