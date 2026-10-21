<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    /**
     * Determine whether the user can view any servers.
     */
    public function viewAny(User $auth): bool
    {
        return $auth->isAdmin();
    }

    /**
     * Determine whether the user can view the server.
     */
    public function view(User $auth, Server $server): bool
    {
        return $auth->isAdmin();
    }

    /**
     * Determine whether the user can create servers.
     */
    public function create(User $auth): bool
    {
        return $auth->isAdmin();
    }

    /**
     * Determine whether the user can update the server.
     */
    public function update(User $auth, Server $server): bool
    {
        return $auth->isAdmin();
    }

    /**
     * Determine whether the user can delete the server.
     */
    public function delete(User $auth, Server $server): bool
    {
        if (! $auth->isAdmin()) {
            return false;
        }

        // Servers with active or provisioned accounts cannot be removed
        return $server->accounts()->doesntExist();
    }
}
