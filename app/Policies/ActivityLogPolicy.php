<?php

namespace App\Policies;

use App\Models\ActivityLog;
use App\Models\User;

/** The owner can read the log; nobody can add to it by hand, change it or remove it. */
class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, ActivityLog $activityLog): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ActivityLog $activityLog): bool
    {
        return false;
    }

    public function delete(User $user, ActivityLog $activityLog): bool
    {
        return false;
    }
}
