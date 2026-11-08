<?php

namespace App\Filament\Resources\Pets\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Pets\PetResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPet extends EditRecord
{
    protected static string $resource = PetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('book')
                ->label('新增預約')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->url(fn () => AppointmentResource::getUrl('create', ['pet_id' => $this->getRecord()->getKey()])),
            DeleteAction::make(),
        ];
    }
}
