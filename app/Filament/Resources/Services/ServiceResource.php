<?php

namespace App\Filament\Resources\Services;

use App\Enums\ServiceType;
use App\Filament\Resources\Services\Pages\ManageServices;
use App\Filament\Support\ExcelExport;
use App\Filament\Support\PhotoUpload;
use App\Models\Service;
use App\Models\ServiceCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Scissors;

    protected static ?string $navigationLabel = '美容服務';

    protected static ?string $modelLabel = '服務項目';

    protected static ?string $pluralModelLabel = '服務項目';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('name')->label('名稱')->required()->maxLength(100),
            Select::make('service_category_id')
                ->label('分類')
                ->options(fn () => ServiceCategory::orderBy('sort_order')->pluck('name', 'id'))
                ->createOptionForm([TextInput::make('name')->label('分類名稱')->required()->maxLength(50)])
                ->createOptionUsing(fn (array $data) => ServiceCategory::create($data)->getKey()),
            Select::make('type')
                ->label('類型')
                ->helperText('一次到店一個主服務；包月方案以主服務計次')
                ->options(ServiceType::class)
                ->default(ServiceType::Standard)
                ->required(),
            TextInput::make('price_small')->label('小型犬貓價格')->numeric()->integer()->minValue(0)->prefix('$')->required(),
            TextInput::make('price_medium')->label('中型犬貓價格')->numeric()->integer()->minValue(0)->prefix('$')->required(),
            TextInput::make('price_large')->label('大型犬貓價格')->numeric()->integer()->minValue(0)->prefix('$')->required(),
            TextInput::make('duration_minutes')
                ->label('預計時間')
                ->numeric()
                ->integer()
                ->minValue(5)
                ->maxValue(600)
                ->default(60)
                ->suffix('分鐘')
                ->required(),
            Toggle::make('is_active')->label('提供中')->default(true)->inline(false),
            Toggle::make('is_bookable_online')
                ->label('開放線上預約')
                ->helperText('關掉後客人在網站上看不到這個服務')
                ->default(true)
                ->inline(false),
            Textarea::make('description')->label('說明')->helperText('會顯示在預約網站上，一句話就好')->rows(2)->columnSpanFull(),
            PhotoUpload::make('photo', 'services')
                ->label('網站照片')
                ->helperText('沒上傳的話，網站會用可愛插圖代替')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->reorderable('sort_order', fn () => auth()->user()->isOwner())
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->with('category'))
            ->columns([
                TextColumn::make('name')->label('名稱')->weight('bold')->searchable(),
                TextColumn::make('category.name')->label('分類')->placeholder('未分類'),
                TextColumn::make('type')->label('類型')->badge(),
                TextColumn::make('price_small')->label('小型')->prefix('$')->numeric(0),
                TextColumn::make('price_medium')->label('中型')->prefix('$')->numeric(0),
                TextColumn::make('price_large')->label('大型')->prefix('$')->numeric(0),
                TextColumn::make('duration_minutes')->label('時間')->suffix(' 分鐘'),
                IconColumn::make('is_active')->label('提供中')->boolean(),
                IconColumn::make('is_bookable_online')->label('線上預約')->boolean(),
            ])
            ->headerActions([
                ExcelExport::action('美容服務', fn (Service $service) => [
                    '名稱' => $service->name,
                    '分類' => $service->category?->name,
                    '類型' => $service->type->getLabel(),
                    '小型價格' => $service->price_small,
                    '中型價格' => $service->price_medium,
                    '大型價格' => $service->price_large,
                    '時間（分鐘）' => $service->duration_minutes,
                    '提供中' => $service->is_active ? '是' : '否',
                ]),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageServices::route('/')];
    }
}
