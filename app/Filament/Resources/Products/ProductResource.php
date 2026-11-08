<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Reached from the product orders page, to keep the sidebar short. */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Cube;

    protected static ?string $modelLabel = '商品';

    protected static ?string $pluralModelLabel = '商品';

    protected static bool $shouldRegisterNavigation = false;

    /** @return array<int, mixed> */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->label('商品名稱')->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('price')->label('售價')->numeric()->integer()->minValue(0)->prefix('$')->required(),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            ...self::fields(),
            TextInput::make('stock')
                ->label('目前庫存')
                ->helperText('留白表示不追蹤。之後的數量請用「調整庫存」修改，才會留下紀錄。')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->visibleOn('create'),
            TextInput::make('low_stock_at')
                ->label('低庫存提醒')
                ->helperText('剩這個數量（含）以下時，出現在訊息通知')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(3)
                ->required(),
            Toggle::make('is_active')->label('販售中')->default(true),
        ])->columns(1);
    }

    /** Goods arriving or a shelf count: the only ways stock changes by hand, so each leaves a line. */
    public static function adjustStockAction(): Action
    {
        return Action::make('adjustStock')
            ->label('調整庫存')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->visible(fn (Product $record) => auth()->user()->can('update', $record))
            ->modalHeading(fn (Product $record) => "調整「{$record->name}」的庫存")
            ->modalDescription(fn (Product $record) => $record->tracksStock() ? "目前庫存 {$record->stock}" : '這項商品還沒有追蹤庫存，調整後就會開始追蹤。')
            ->modalSubmitActionLabel('確認調整')
            ->schema([
                Select::make('mode')
                    ->label('類型')
                    ->options(['restock' => '進貨（增加數量）', 'count' => '盤點（改成實際數量）'])
                    ->default('restock')
                    ->required()
                    ->selectablePlaceholder(false)
                    ->live(),
                TextInput::make('quantity')
                    ->label(fn (Get $get) => $get('mode') === 'count' ? '實際數量' : '進貨數量')
                    ->numeric()
                    ->integer()
                    ->minValue(fn (Get $get) => $get('mode') === 'count' ? 0 : 1)
                    ->required(),
                TextInput::make('note')->label('備註')->maxLength(100),
            ])
            ->action(function (Product $record, array $data) {
                $data['mode'] === 'count'
                    ? $record->countStock((int) $data['quantity'], $data['note'] ?? null)
                    : $record->restock((int) $data['quantity'], $data['note'] ?? null);

                Notification::make()->title("庫存已更新為 {$record->stock}")->success()->send();
            });
    }

    public static function stockHistoryAction(): Action
    {
        return Action::make('stockHistory')
            ->label('庫存紀錄')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->modalHeading(fn (Product $record) => "「{$record->name}」的庫存紀錄")
            ->modalContent(fn (Product $record) => view('filament.products.stock-history', [
                'movements' => $record->stockMovements()->with(['user', 'order'])->latest('id')->limit(100)->get(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('關閉');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->reorderable('sort_order', fn () => auth()->user()->isOwner())
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('商品名稱')->weight('bold')->searchable(),
                TextColumn::make('price')->label('售價')->prefix('$')->numeric(0),
                TextColumn::make('stock')
                    ->label('庫存')
                    ->placeholder('不追蹤')
                    ->color(fn (Product $record) => $record->isLowOnStock() ? 'danger' : null)
                    ->weight(fn (Product $record) => $record->isLowOnStock() ? 'bold' : null),
                IconColumn::make('is_active')->label('販售中')->boolean(),
            ])
            ->recordActions([
                self::adjustStockAction(),
                self::stockHistoryAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageProducts::route('/')];
    }
}
