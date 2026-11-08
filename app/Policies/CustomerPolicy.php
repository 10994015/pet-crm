<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Customer $customer): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Customer $customer): bool
    {
        return true;
    }

    /** Customers with appointments keep their history, so only unused records can be removed. */
    public function delete(User $user, Customer $customer): bool
    {
        return $user->isOwner()
            && ! $customer->appointments()->exists()
            && ! $customer->packages()->exists()
            && ! $customer->productOrders()->exists();
    }
}
