<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function getTabs(): array
    {
        $awaiting = Appointment::awaitingConfirmation()->count();

        return [
            ...($awaiting ? ['online' => Tab::make('線上待確認')
                ->badge($awaiting)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->awaitingConfirmation())] : []),
            'today' => Tab::make('今天')->modifyQueryUsing(
                fn (Builder $query) => $query->whereDate('starts_at', today())
            ),
            'tomorrow' => Tab::make('明天')->modifyQueryUsing(
                fn (Builder $query) => $query->whereDate('starts_at', today()->addDay())
            ),
            'upcoming' => Tab::make('之後')->modifyQueryUsing(
                fn (Builder $query) => $query
                    ->where('starts_at', '>=', now())
                    ->whereIn('status', AppointmentStatus::active())
            ),
            'all' => Tab::make('全部')->modifyQueryUsing(
                fn (Builder $query) => $query->reorder('starts_at', 'desc')
            ),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'today';
    }
}
