<?php

namespace App\Filament\Resources\Appointments\RelationManagers;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Visit history, shown on both the customer and the pet pages. */
class AppointmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'appointments';

    protected static ?string $title = '美容與消費紀錄';

    protected static ?string $modelLabel = '預約';

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['pet', 'items', 'groomer']))
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('starts_at')->label('日期')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('pet.name')->label('寵物')->weight('bold'),
                TextColumn::make('items.name')->label('服務')->badge()->color('gray'),
                TextColumn::make('groomer.name')->label('美容師')->placeholder('未指定'),
                TextColumn::make('status')->label('狀態')->badge(),
                TextColumn::make('total')->label('金額')->prefix('$')->numeric(0),
                TextColumn::make('grooming_notes')->label('美容紀錄')->limit(40)->wrap()->placeholder('—'),
                ImageColumn::make('after_photos')->label('完成照片')->disk('public')->stacked()->limitedRemainingText()->limit(3),
            ])
            ->recordUrl(fn (Appointment $record) => auth()->user()->can('update', $record)
                ? AppointmentResource::getUrl('edit', ['record' => $record])
                : null);
    }
}
