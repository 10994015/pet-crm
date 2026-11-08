<?php

namespace App\Filament\Resources\ProductOrders\Pages;

use App\Filament\Resources\ProductOrders\ProductOrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListProductOrders extends ListRecords
{
    protected static string $resource = ProductOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('products')
                ->label('商品清單')
                ->icon(Heroicon::OutlinedCube)
                ->color('gray')
                ->visible(fn () => auth()->user()->can('create', Product::class))
                ->url(ProductResource::getUrl('index')),
            CreateAction::make(),
        ];
    }
}
