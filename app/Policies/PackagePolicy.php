<?php

namespace App\Policies;

use App\Models\Package;
use App\Models\User;

class PackagePolicy
{
    /**
     * Determine whether the user can view any packages.
     */
    public function viewAny(User $auth): bool
    {
        return $auth->isAdmin() || $auth->isReseller() || $auth->isCustomer();
    }

    /**
     * Determine whether the user can view the specific package.
     */
    public function view(User $auth, Package $package): bool
    {
        if ($auth->isAdmin()) {
            return true;
        }

        if ($auth->isReseller()) {
            // Reseller can view global system packages and their own custom packages
            return $package->reseller_owner_id === null || $package->reseller_owner_id === $auth->id;
        }

        // Customer can view global system packages, packages of their parent reseller, or assigned packages
        if ($package->reseller_owner_id === null) {
            return true;
        }

        if ($auth->parent_id && $package->reseller_owner_id === $auth->parent_id) {
            return true;
        }

        return $auth->accounts()->where('package_id', $package->id)->exists();
    }

    /**
     * Determine whether the user can create packages.
     */
    public function create(User $auth): bool
    {
        return $auth->isAdmin() || $auth->isReseller();
    }

    /**
     * Determine whether the user can update the package.
     */
    public function update(User $auth, Package $package): bool
    {
        if ($auth->isAdmin()) {
            return true;
        }

        if ($auth->isReseller()) {
            // Reseller can only update their own packages, never system packages
            return $package->reseller_owner_id === $auth->id;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the package.
     */
    public function delete(User $auth, Package $package): bool
    {
        if ($package->accounts()->exists()) {
            return false;
        }

        if ($auth->isAdmin()) {
            return true;
        }

        if ($auth->isReseller()) {
            return $package->reseller_owner_id === $auth->id;
        }

        return false;
    }
}
