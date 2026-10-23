<?php

namespace App\Policies;

use App\Models\Backup;
use App\Models\User;

class BackupPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Backup $backup): bool
    {
        $backup->loadMissing('account.user');

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isReseller()) {
            return $backup->account->user_id === $user->id || $backup->account->user->isDescendantOf($user);
        }

        return $backup->account->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Backup $backup): bool
    {
        return $this->view($user, $backup);
    }

    public function delete(User $user, Backup $backup): bool
    {
        return $this->view($user, $backup);
    }
}
