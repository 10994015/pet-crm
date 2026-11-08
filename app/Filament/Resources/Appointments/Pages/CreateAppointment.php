<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Pages\Concerns\PicksServices;
use Filament\Resources\Pages\CreateRecord;

class CreateAppointment extends CreateRecord
{
    use PicksServices;

    protected static string $resource = AppointmentResource::class;

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('index');
    }
}
