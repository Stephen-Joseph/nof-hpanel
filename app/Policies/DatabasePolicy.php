<?php

namespace App\Policies;

use App\Models\Database;
use App\Models\User;

class DatabasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Database $database): bool
    {
        $database->loadMissing('account.user');

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isReseller()) {
            return $database->account->user_id === $user->id || $database->account->user->isDescendantOf($user);
        }

        return $database->account->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Database $database): bool
    {
        return $this->view($user, $database);
    }

    public function delete(User $user, Database $database): bool
    {
        return $this->view($user, $database);
    }
}
