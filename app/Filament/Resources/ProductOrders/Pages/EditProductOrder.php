<?php

namespace App\Filament\Resources\ProductOrders\Pages;

use App\Filament\Resources\ProductOrders\ProductOrderResource;
use Filament\Resources\Pages\EditRecord;

class EditProductOrder extends EditRecord
{
    protected static string $resource = ProductOrderResource::class;

    /** The order, its lines and the stock they take either all happen or none do. */
    protected ?bool $hasDatabaseTransactions = true;

    protected function afterSave(): void
    {
        ProductOrderResource::warnAboutStock($this->getRecord());
    }
}
