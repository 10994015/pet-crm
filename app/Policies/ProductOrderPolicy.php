<?php

namespace App\Policies;

use App\Models\ProductOrder;
use App\Models\User;

class ProductOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProductOrder $productOrder): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** Sales are revenue records: only the owner corrects them, and never after a refund. */
    public function update(User $user, ProductOrder $productOrder): bool
    {
        return $user->isOwner() && $productOrder->refunded_at === null;
    }

    public function delete(User $user, ProductOrder $productOrder): bool
    {
        return false;
    }
}
