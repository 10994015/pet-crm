<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Shown on the notifications page, not the dashboard, and only when something is running out. */
class LowStockProducts extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Product::lowOnStock()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(Product::lowOnStock())
            ->defaultSort('stock')
            ->heading('商品庫存不足')
            ->description('進貨或盤點後，數量恢復就不會再列出。')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('商品')->weight('bold'),
                TextColumn::make('stock')->label('庫存')->color('danger')->weight('bold'),
                TextColumn::make('low_stock_at')->label('提醒數量'),
            ])
            ->recordActions([ProductResource::adjustStockAction()]);
    }
}
