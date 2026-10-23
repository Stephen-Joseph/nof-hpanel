<?php

namespace App\Policies;

use App\Models\DatabaseUser;
use App\Models\User;

class DatabaseUserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DatabaseUser $databaseUser): bool
    {
        $databaseUser->loadMissing('account.user');

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isReseller()) {
            return $databaseUser->account->user_id === $user->id || $databaseUser->account->user->isDescendantOf($user);
        }

        return $databaseUser->account->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, DatabaseUser $databaseUser): bool
    {
        return $this->view($user, $databaseUser);
    }

    public function delete(User $user, DatabaseUser $databaseUser): bool
    {
        return $this->view($user, $databaseUser);
    }
}
