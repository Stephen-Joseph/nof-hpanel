<?php

namespace App\Policies;

use App\Models\AgentJob;
use App\Models\User;

class AgentJobPolicy
{
    /**
     * Determine whether the user can view any agent jobs.
     */
    public function viewAny(User $auth): bool
    {
        return $auth->isAdmin();
    }

    /**
     * Determine whether the user can view the agent job.
     */
    public function view(User $auth, AgentJob $agentJob): bool
    {
        return $auth->isAdmin();
    }

    /**
     * Determine whether the user can create an agent job.
     */
    public function create(User $auth): bool
    {
        return $auth->isAdmin();
    }

    /**
     * Determine whether the user can retry an agent job.
     */
    public function retry(User $auth, AgentJob $agentJob): bool
    {
        return $auth->isAdmin();
    }
}
