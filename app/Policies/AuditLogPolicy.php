<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    /**
     * Determine whether the user can view audit log listing.
     */
    public function viewAny(User $auth): bool
    {
        return $auth->isAdmin();
    }

    /**
     * Determine whether the user can view a specific audit log.
     */
    public function view(User $auth, AuditLog $log): bool
    {
        return $auth->isAdmin();
    }
}
