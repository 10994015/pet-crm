<?php

namespace App\Filament\Resources\Customers;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\Appointments\RelationManagers\AppointmentsRelationManager;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\RelationManagers\PetsRelationManager;
use App\Filament\Resources\Packages\RelationManagers\PackagesRelationManager;
use App\Filament\Resources\Pets\PetResource;
use App\Filament\Resources\ProductOrders\RelationManagers\ProductOrdersRelationManager;
use App\Filament\Support\ExcelExport;
use App\Models\Customer;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Users;

    protected static ?string $navigationLabel = '客戶管理';

    protected static ?string $modelLabel = '客戶';

    protected static ?string $pluralModelLabel = '客戶';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    /** @return array<int, mixed> */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->label('姓名')->required()->maxLength(100),
            TextInput::make('phone')
                ->label('電話')
                ->tel()
                ->required()
                ->maxLength(30)
                ->rules([
                    fn ($record): Closure => function (string $attribute, $value, Closure $fail) use ($record) {
                        $taken = Customer::where('phone', Customer::normalizePhone($value))
                            ->when($record instanceof Customer, fn ($query) => $query->whereKeyNot($record->getKey()))
                            ->exists();

                        if ($taken) {
                            $fail('這支電話已經有客戶使用，請先搜尋是否為同一位客戶。');
                        }
                    },
                ]),
            TextInput::make('backup_phone')->label('備用電話')->tel()->maxLength(30),
            TextInput::make('line_id')->label('LINE ID')->maxLength(100),
            TextInput::make('email')->label('電子郵件')->email()->maxLength(255),
            TextInput::make('address')->label('地址')->maxLength(255)->columnSpanFull(),
            Textarea::make('notes')->label('備註')->rows(3)->columnSpanFull(),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('基本資料')->columns(2)->schema([
                ...self::fields(),
                Toggle::make('online_booking_blocked')
                    ->label('封鎖線上預約')
                    ->helperText('開啟後這支電話不能在網站預約，要改用電話聯絡。已有的預約不受影響。')
                    ->visibleOn('edit')
                    ->columnSpanFull(),
            ]),
            Section::make('寵物')
                ->description('可以一次建立多隻，之後也能在客戶頁補充更多資料。')
                ->visibleOn('create')
                ->schema([
                    Repeater::make('pets')
                        ->hiddenLabel()
                        ->relationship()
                        ->columns(2)
                        ->defaultItems(1)
                        ->reorderable(false)
                        ->addActionLabel('再加一隻寵物')
                        ->schema(PetResource::quickFields()),
                ]),
            Section::make('消費概況')
                ->columns(4)
                ->visibleOn('edit')
                ->schema([
                    TextEntry::make('visit_count')->label('來店次數')->state(
                        fn (Customer $record) => self::completedVisits($record)->count().' 次'
                    ),
                    TextEntry::make('total_spent')->label('累計消費')->visible(fn () => auth()->user()->isOwner())->state(
                        fn (Customer $record) => '$'.number_format($record->lifetimeSpending())
                    ),
                    TextEntry::make('last_visit')->label('上次來店')->state(
                        fn (Customer $record) => self::completedVisits($record)->max('starts_at') ?? '尚無紀錄'
                    ),
                    TextEntry::make('membership')->label('網站會員')->badge()
                        ->color(fn (Customer $record) => $record->isMember() ? 'success' : 'gray')
                        ->state(fn (Customer $record) => match (true) {
                            $record->isMember() => '已開通（'.$record->member_since?->format('Y/m/d').'）',
                            $record->activation_expires_at?->isFuture() => '已發開通碼，'.$record->activation_expires_at->format('n/j').' 前有效',
                            default => '尚未開通',
                        }),
                    TextEntry::make('privacy_consent')->label('隱私權同意')
                        ->state(fn (Customer $record) => $record->privacy_accepted_at
                            ? '已於網站同意（'.$record->privacy_accepted_at->format('Y/m/d H:i').'）'
                            : '沒有網站同意紀錄'),
                ]),
        ]);
    }

    private static function completedVisits(Customer $customer)
    {
        return $customer->appointments()->where('status', AppointmentStatus::Completed);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('pets')
                ->withMax(['appointments as last_visit_at' => fn (Builder $query) => $query
                    ->where('status', AppointmentStatus::Completed)], 'starts_at'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('姓名')->sortable()->searchable(
                    query: fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                        ->search($search)
                        ->orWhere('line_id', 'like', "%{$search}%")
                        ->orWhereHas('pets', fn (Builder $query) => $query->where('name', 'like', "%{$search}%")))
                ),
                TextColumn::make('phone')
                    ->label('電話')
                    ->url(fn (Customer $record) => "tel:{$record->phone}")
                    ->color('primary'),
                TextColumn::make('pets.name')->label('寵物')->badge(),
                TextColumn::make('member_since')->label('網站會員')->date('Y/m/d')->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('line_id')->label('LINE ID')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_visit_at')->label('上次來店')->date('Y/m/d')->placeholder('—')->sortable(),
            ])
            ->searchPlaceholder('搜尋姓名、電話、LINE 或寵物名')
            ->headerActions([
                ExcelExport::action('客戶資料', fn (Customer $customer) => [
                    '姓名' => $customer->name,
                    '電話' => $customer->phone,
                    '備用電話' => $customer->backup_phone,
                    '電子郵件' => $customer->email,
                    'LINE ID' => $customer->line_id,
                    '地址' => $customer->address,
                    '寵物' => $customer->pets->pluck('name')->implode('、'),
                    '上次來店' => $customer->last_visit_at,
                    '備註' => $customer->notes,
                ]),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone', 'line_id'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['電話' => $record->phone];
    }

    public static function getRelations(): array
    {
        return [
            PetsRelationManager::class,
            PackagesRelationManager::class,
            AppointmentsRelationManager::class,
            ProductOrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
