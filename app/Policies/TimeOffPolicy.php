<?php

namespace App\Policies;

use App\Models\TimeOff;
use App\Models\User;

/** Everyone can see who's away; only the owner changes it. */
class TimeOffPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TimeOff $timeOff): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, TimeOff $timeOff): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, TimeOff $timeOff): bool
    {
        return $user->isOwner();
    }
}
