<?php

namespace App\Policies;

use App\Models\ReportingPeriod;
use App\Models\User;

class ReportingPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // any authenticated user
    }

    public function view(User $user, ReportingPeriod $period): bool
    {
        return true; // any authenticated user
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ReportingPeriod $period): bool
    {
        return $user->isAdmin();
    }

    /**
     * Status transitions (open/close/reopen/approve) are admin actions.
     */
    public function transition(User $user, ReportingPeriod $period): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ReportingPeriod $period): bool
    {
        // Periods are lifecycle-managed (closed), never hard-deleted,
        // to protect historical reporting data.
        return false;
    }
}
