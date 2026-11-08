<?php

namespace App\Policies;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** Completed appointments are revenue records, so only the owner can change them. */
    public function update(User $user, Appointment $appointment): bool
    {
        return $user->isOwner() || $appointment->status !== AppointmentStatus::Completed;
    }

    /** Paid appointments can't be deleted; undo the checkout first. */
    public function delete(User $user, Appointment $appointment): bool
    {
        return $user->isOwner() && $appointment->status !== AppointmentStatus::Completed;
    }
}
