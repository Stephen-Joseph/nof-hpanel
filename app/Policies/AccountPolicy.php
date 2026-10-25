<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    /**
     * Determine whether the user can view any accounts.
     */
    public function viewAny(User $auth): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the specific hosting account.
     */
    public function view(User $auth, Account $account): bool
    {
        if ($auth->isAdmin()) {
            return true;
        }

        if ($auth->isReseller()) {
            return $account->user_id === $auth->id || $account->user->isDescendantOf($auth);
        }

        return $account->user_id === $auth->id;
    }

    /**
     * Determine whether the user can manage files (upload, extract, archive) for the account.
     */
    public function manageFiles(User $auth, Account $account): bool
    {
        if ($auth->isAdmin()) {
            return true;
        }

        if ($auth->isReseller()) {
            return $account->user_id === $auth->id || $account->user->isDescendantOf($auth);
        }

        return $account->user_id === $auth->id;
    }

    /**
     * Determine whether the user can create accounts.
     */
    public function create(User $auth): bool
    {
        return $auth->isAdmin() || $auth->isReseller();
    }

    /**
     * Determine whether the user can update the account (e.g. change package, status).
     */
    public function update(User $auth, Account $account): bool
    {
        if ($auth->isAdmin()) {
            return true;
        }

        if ($auth->isReseller()) {
            return $account->user_id === $auth->id || $account->user->isDescendantOf($auth);
        }

        return false;
    }

    /**
     * Determine whether the user can delete/terminate the account.
     */
    public function delete(User $auth, Account $account): bool
    {
        if ($auth->isAdmin()) {
            return true;
        }

        if ($auth->isReseller()) {
            return $account->user->isDescendantOf($auth);
        }

        return false;
    }
}
