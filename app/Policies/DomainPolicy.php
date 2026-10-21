<?php

namespace App\Policies;

use App\Models\Domain;
use App\Models\User;

class DomainPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Domain $domain): bool
    {
        $domain->loadMissing('account.user');

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isReseller()) {
            return $domain->account->user_id === $user->id || $domain->account->user->isDescendantOf($user);
        }

        return $domain->account->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Domain $domain): bool
    {
        return $this->view($user, $domain);
    }

    public function delete(User $user, Domain $domain): bool
    {
        return $this->view($user, $domain);
    }
}
