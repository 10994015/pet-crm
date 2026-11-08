<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
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
            Toggle::make('is_active')->label('販售中')->default(true),
        ])->columns(1);
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
                IconColumn::make('is_active')->label('販售中')->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageProducts::route('/')];
    }
}
