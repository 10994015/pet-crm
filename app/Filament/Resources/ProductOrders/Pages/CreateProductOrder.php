<?php

namespace App\Filament\Resources\ProductOrders\Pages;

use App\Filament\Resources\ProductOrders\ProductOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductOrder extends CreateRecord
{
    protected static string $resource = ProductOrderResource::class;

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('index');
    }
}
