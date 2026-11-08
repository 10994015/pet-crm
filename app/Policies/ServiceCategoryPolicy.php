<?php

namespace App\Policies;

use App\Models\ServiceCategory;
use App\Models\User;

class ServiceCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ServiceCategory $serviceCategory): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, ServiceCategory $serviceCategory): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, ServiceCategory $serviceCategory): bool
    {
        return $user->isOwner() && ! $serviceCategory->services()->exists();
    }
}
