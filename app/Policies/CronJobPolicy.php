<?php

namespace App\Policies;

use App\Models\CronJob;
use App\Models\User;

class CronJobPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CronJob $cronJob): bool
    {
        $cronJob->loadMissing('account.user');

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isReseller()) {
            return $cronJob->account->user_id === $user->id || $cronJob->account->user->isDescendantOf($user);
        }

        return $cronJob->account->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, CronJob $cronJob): bool
    {
        return $this->view($user, $cronJob);
    }

    public function delete(User $user, CronJob $cronJob): bool
    {
        return $this->view($user, $cronJob);
    }
}
