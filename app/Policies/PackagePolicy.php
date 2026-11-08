<?php

namespace App\Policies;

use App\Models\Package;
use App\Models\User;

class PackagePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Package $package): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** Extending and ending a package moves prepaid money, so it stays with the owner. */
    public function update(User $user, Package $package): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, Package $package): bool
    {
        return false;
    }
}
