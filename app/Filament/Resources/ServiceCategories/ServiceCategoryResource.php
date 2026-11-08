<?php

namespace App\Filament\Resources\ServiceCategories;

use App\Filament\Resources\ServiceCategories\Pages\ManageServiceCategories;
use App\Filament\Support\ExcelExport;
use App\Models\ServiceCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Reached from the services page, to keep the sidebar short. */
class ServiceCategoryResource extends Resource
{
    protected static ?string $model = ServiceCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Tag;

    protected static ?string $modelLabel = '服務分類';

    protected static ?string $pluralModelLabel = '服務分類';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('分類名稱')->required()->maxLength(50),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->reorderable('sort_order', fn () => auth()->user()->isOwner())
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->withCount('services'))
            ->columns([
                TextColumn::make('name')->label('分類名稱')->weight('bold'),
                TextColumn::make('services_count')->label('服務數量'),
            ])
            ->headerActions([
                ExcelExport::action('服務分類', fn (ServiceCategory $category) => [
                    '分類名稱' => $category->name,
                    '服務數量' => $category->services_count,
                ]),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageServiceCategories::route('/')];
    }
}
