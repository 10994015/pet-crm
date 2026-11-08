<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Pets\PetResource;
use App\Models\Pet;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PetsRelationManager extends RelationManager
{
    protected static string $relationship = 'pets';

    protected static ?string $title = '寵物';

    protected static ?string $modelLabel = '寵物';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(PetResource::fields())->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->recordTitleAttribute('name')
            ->columns([
                ImageColumn::make('photo')->label('照片')->disk('public')->circular(),
                TextColumn::make('name')->label('名字')->weight('bold'),
                TextColumn::make('breed')->label('品種')->placeholder('—'),
                TextColumn::make('size')->label('體型'),
                TextColumn::make('temperament')->label('個性'),
            ])
            ->headerActions([CreateAction::make()])
            ->recordUrl(fn (Pet $record) => PetResource::getUrl('edit', ['record' => $record]))
            ->recordActions([
                Action::make('book')
                    ->label('預約')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->url(fn (Pet $record) => AppointmentResource::getUrl('create', ['pet_id' => $record->getKey()])),
            ]);
    }
}
