<?php

namespace App\Filament\Resources\ProductOrders\Pages;

use App\Filament\Resources\ProductOrders\ProductOrderResource;
use Filament\Resources\Pages\EditRecord;

class EditProductOrder extends EditRecord
{
    protected static string $resource = ProductOrderResource::class;
}
