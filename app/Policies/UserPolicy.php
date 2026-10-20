<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any users.
     */
    public function viewAny(User $auth): bool
    {
        return $auth->isAdmin() || $auth->isReseller();
    }

    /**
     * Determine whether the user can view the specific target user.
     */
    public function view(User $auth, User $target): bool
    {
        if ($auth->isAdmin()) {
            return true;
        }

        if ($auth->isReseller()) {
            return $auth->id === $target->id || $target->isDescendantOf($auth);
        }

        return $auth->id === $target->id;
    }

    /**
     * Determine whether the user can create new users.
     */
    public function create(User $auth): bool
    {
        return $auth->isAdmin() || $auth->isReseller();
    }

    /**
     * Determine whether the user can update the target user.
     */
    public function update(User $auth, User $target): bool
    {
        if ($auth->isAdmin()) {
            return true;
        }

        if ($auth->isReseller()) {
            // Reseller can update self or their own customers/sub-users
            return $auth->id === $target->id || $target->isDescendantOf($auth);
        }

        // Customer can only update their own profile
        return $auth->id === $target->id;
    }

    /**
     * Determine whether the user can delete the target user.
     */
    public function delete(User $auth, User $target): bool
    {
        // Users cannot delete themselves
        if ($auth->id === $target->id) {
            return false;
        }

        if ($auth->isAdmin()) {
            return true;
        }

        if ($auth->isReseller()) {
            return $target->isDescendantOf($auth);
        }

        return false;
    }
}
