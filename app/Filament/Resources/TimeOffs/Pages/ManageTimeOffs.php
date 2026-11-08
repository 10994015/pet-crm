<?php

namespace App\Filament\Resources\TimeOffs\Pages;

use App\Filament\Resources\TimeOffs\TimeOffResource;
use App\Models\TimeOff;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTimeOffs extends ManageRecords
{
    protected static string $resource = TimeOffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->after(fn (TimeOff $record) => TimeOffResource::warnAboutBookings($record)),
        ];
    }
}
