<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\ServiceCategories\ServiceCategoryResource;
use App\Models\ServiceCategory;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageServices extends ManageRecords
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('categories')
                ->label('服務分類')
                ->icon(Heroicon::OutlinedTag)
                ->color('gray')
                ->visible(fn () => auth()->user()->can('create', ServiceCategory::class))
                ->url(ServiceCategoryResource::getUrl('index')),
            CreateAction::make(),
        ];
    }
}
