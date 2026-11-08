<?php

namespace App\Filament\Resources\Pets;

use App\Enums\PetGender;
use App\Enums\PetSize;
use App\Enums\PetSpecies;
use App\Enums\PetTemperament;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\RelationManagers\AppointmentsRelationManager;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Packages\RelationManagers\PackagesRelationManager;
use App\Filament\Support\ExcelExport;
use App\Filament\Resources\Pets\Pages\CreatePet;
use App\Filament\Resources\Pets\Pages\EditPet;
use App\Filament\Resources\Pets\Pages\ListPets;
use App\Filament\Support\PhotoUpload;
use App\Models\Customer;
use App\Models\Pet;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PetResource extends Resource
{
    protected static ?string $model = Pet::class;

    protected static string|BackedEnum|null $navigationIcon = 'bibi-paw';

    protected static ?string $navigationLabel = '寵物資料';

    protected static ?string $modelLabel = '寵物';

    protected static ?string $pluralModelLabel = '寵物';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Pet fields, shared by the pet page, the customer page and the quick-add dialog.
     *
     * @return array<int, mixed>
     */
    public static function fields(bool $withCustomer = false): array
    {
        return [
            ...($withCustomer ? [self::customerSelect()] : []),
            TextInput::make('name')->label('名字')->required()->maxLength(100),
            Select::make('species')->label('種類')->options(PetSpecies::class)->default(PetSpecies::Dog)->required(),
            TextInput::make('breed')->label('品種')->maxLength(100),
            Select::make('size')
                ->label('體型')
                ->helperText('會依體型自動帶入服務價格')
                ->options(PetSize::class)
                ->default(PetSize::Small)
                ->required(),
            Select::make('gender')->label('性別')->options(PetGender::class)->default(PetGender::Unknown)->required(),
            Toggle::make('is_neutered')->label('已結紮')->inline(false),
            DatePicker::make('birthday')->label('生日')->maxDate(today()),
            TextInput::make('weight_kg')->label('體重')->numeric()->minValue(0)->maxValue(200)->suffix('公斤'),
            TextInput::make('coat_color')->label('毛色')->maxLength(50),
            TextInput::make('chip_number')->label('晶片編號')->maxLength(50),
            Select::make('temperament')
                ->label('個性')
                ->options(PetTemperament::class)
                ->default(PetTemperament::Gentle)
                ->required(),
            Textarea::make('allergies')->label('過敏')->rows(2)->columnSpanFull(),
            Textarea::make('health_notes')->label('健康狀況')->rows(2)->columnSpanFull(),
            Textarea::make('grooming_preferences')
                ->label('美容偏好')
                ->placeholder('例如：身體留 1 公分、耳朵不要剪短')
                ->rows(2)
                ->columnSpanFull(),
            DatePicker::make('rabies_vaccine_expires_on')->label('狂犬病疫苗到期日'),
            TextInput::make('visit_interval_weeks')
                ->label('建議回訪週期')
                ->helperText('結帳後依此週期排入回訪提醒')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(52)
                ->default(6)
                ->required()
                ->suffix('週'),
            PhotoUpload::make('photo', 'pets')->label('照片')->columnSpanFull(),
            Toggle::make('is_active')
                ->label('持續服務中')
                ->helperText('關閉後不再出現在回訪提醒，例如寵物已過世或客戶已搬家')
                ->default(true)
                ->columnSpanFull(),
        ];
    }

    /**
     * The essentials, for adding a pet while creating its owner.
     *
     * @return array<int, mixed>
     */
    public static function quickFields(): array
    {
        return [
            TextInput::make('name')->label('名字')->required()->maxLength(100),
            Select::make('species')->label('種類')->options(PetSpecies::class)->default(PetSpecies::Dog)->required(),
            TextInput::make('breed')->label('品種')->maxLength(100),
            Select::make('size')->label('體型')->options(PetSize::class)->default(PetSize::Small)->required(),
            Select::make('gender')->label('性別')->options(PetGender::class)->default(PetGender::Unknown)->required(),
            Select::make('temperament')->label('個性')->options(PetTemperament::class)->default(PetTemperament::Gentle)->required(),
            TextInput::make('chip_number')->label('晶片編號')->maxLength(50),
            TextInput::make('allergies')->label('過敏')->maxLength(255),
        ];
    }

    public static function customerSelect(): Select
    {
        return Select::make('customer_id')
            ->label('飼主')
            ->searchable()
            ->searchPrompt('輸入姓名或電話搜尋')
            ->getSearchResultsUsing(fn (string $search) => Customer::search($search)
                ->limit(20)
                ->get()
                ->mapWithKeys(fn (Customer $customer) => [$customer->getKey() => $customer->label]))
            ->getOptionLabelUsing(fn ($value) => Customer::find($value)?->label)
            ->createOptionForm(CustomerResource::fields())
            ->createOptionUsing(fn (array $data) => Customer::create($data)->getKey())
            ->createOptionModalHeading('新增客戶')
            ->default(fn () => request()->integer('customer_id') ?: null)
            ->required()
            ->columnSpanFull();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('寵物資料')->columns(2)->schema(self::fields(withCustomer: true)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query) => $query->with('customer'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('photo')->label('照片')->disk('public')->circular(),
                TextColumn::make('name')
                    ->label('名字')
                    ->weight('bold')
                    ->description(fn (Pet $record) => implode('、', $record->warnings()) ?: null)
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search) => $query->search($search)),
                TextColumn::make('customer.name')
                    ->label('飼主')
                    ->description(fn (Pet $record) => $record->customer->phone),
                TextColumn::make('species')->label('種類')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('breed')->label('品種')->placeholder('—'),
                TextColumn::make('size')->label('體型'),
                TextColumn::make('next_visit_due_on')->label('建議回訪日')->date('Y/m/d')->placeholder('—')->sortable(),
            ])
            ->searchPlaceholder('搜尋寵物名、飼主姓名或電話')
            ->headerActions([
                ExcelExport::action('寵物資料', fn (Pet $pet) => [
                    '名字' => $pet->name,
                    '飼主' => $pet->customer->name,
                    '飼主電話' => $pet->customer->phone,
                    '種類' => $pet->species->getLabel(),
                    '品種' => $pet->breed,
                    '體型' => $pet->size->getLabel(),
                    '性別' => $pet->gender->getLabel(),
                    '生日' => $pet->birthday?->format('Y/m/d'),
                    '晶片編號' => $pet->chip_number,
                    '過敏' => $pet->allergies,
                    '健康狀況' => $pet->health_notes,
                    '建議回訪日' => $pet->next_visit_due_on?->format('Y/m/d'),
                ]),
            ])
            ->filters([
                SelectFilter::make('species')->label('種類')->options(PetSpecies::class),
                SelectFilter::make('size')->label('體型')->options(PetSize::class),
            ])
            ->recordActions([
                Action::make('book')
                    ->label('預約')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->url(fn (Pet $record) => AppointmentResource::getUrl('create', ['pet_id' => $record->getKey()])),
                EditAction::make(),
            ]);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('customer');
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['飼主' => $record->customer->label];
    }

    public static function getRelations(): array
    {
        return [PackagesRelationManager::class, AppointmentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPets::route('/'),
            'create' => CreatePet::route('/create'),
            'edit' => EditPet::route('/{record}/edit'),
        ];
    }
}
