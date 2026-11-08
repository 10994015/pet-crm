<?php

namespace App\Filament\Resources\ProductOrders\Pages;

use App\Filament\Resources\ProductOrders\ProductOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductOrder extends CreateRecord
{
    protected static string $resource = ProductOrderResource::class;

    /** The order, its lines and the stock they take either all happen or none do. */
    protected ?bool $hasDatabaseTransactions = true;

    protected function afterCreate(): void
    {
        ProductOrderResource::warnAboutStock($this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('index');
    }
}
