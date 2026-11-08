<?php

namespace App\Filament\Resources\ProductOrders\RelationManagers;

use App\Filament\Resources\ProductOrders\ProductOrderResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** A customer's product purchases, shown on their page. */
class ProductOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'productOrders';

    protected static ?string $title = '商品訂單';

    protected static ?string $modelLabel = '商品訂單';

    public function table(Table $table): Table
    {
        return ProductOrderResource::columns($table)->searchable(false);
    }
}
