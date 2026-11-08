<?php

namespace App\Filament\Resources\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Pets\PetResource;
use App\Filament\Support\ExcelExport;
use App\Filament\Support\PhotoUpload;
use App\Enums\ServiceType;
use App\Models\Appointment;
use App\Models\Package;
use App\Models\Pet;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AppointmentResource extends Resource
{
    protected static ?string $model = Appointment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CalendarDays;

    protected static ?string $navigationLabel = '預約管理';

    protected static ?string $modelLabel = '預約';

    protected static ?string $pluralModelLabel = '預約';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('預約資訊')->columns(2)->schema([
                self::petSelect(),
                TextEntry::make('pet_warnings')
                    ->label('注意事項')
                    ->color('danger')
                    ->weight('bold')
                    ->state(fn (Get $get) => implode('、', Pet::find($get('pet_id'))?->warnings() ?? []))
                    ->visible(fn (Get $get) => filled(Pet::find($get('pet_id'))?->warnings()))
                    ->columnSpanFull(),
                self::packageSelect(),
                DateTimePicker::make('starts_at')
                    ->label('開始時間')
                    ->seconds(false)
                    ->default(fn () => self::defaultStart())
                    ->required()
                    ->rules([fn (Get $get, $record): Closure => self::conflictRule($get, $record)]),
                Select::make('groomer_id')
                    ->label('美容師')
                    ->options(fn (?Appointment $record) => User::activeGroomers()
                        ->when($record?->groomer_id, fn (Builder $query, $id) => $query->orWhere('id', $id))
                        ->pluck('name', 'id'))
                    ->default(fn () => request()->integer('groomer_id') ?: null)
                    ->placeholder('尚未指定'),
                Textarea::make('notes')->label('預約備註')->rows(2)->columnSpanFull(),
            ]),
            Section::make('服務項目')->schema([
                ViewField::make('service_picker')
                    ->hiddenLabel()
                    ->view('filament.forms.service-picker')
                    ->dehydrated(false),
                Repeater::make('items')
                    ->label('已選服務')
                    ->relationship()
                    ->columns(2)
                    ->minItems(1)
                    ->defaultItems(0)
                    ->required()
                    ->addable(false)
                    ->reorderable(false)
                    ->live()
                    ->itemLabel(fn (array $state) => $state['name'] ?? null)
                    ->schema([
                        TextInput::make('price')->label('價格')->numeric()->integer()->minValue(0)->prefix('$')->required()->live(onBlur: true),
                        TextInput::make('duration_minutes')->label('時間')->numeric()->integer()->minValue(0)->suffix('分鐘')->required()->live(onBlur: true),
                        Hidden::make('service_id')->required(),
                        Hidden::make('name'),
                    ]),
                TextEntry::make('estimate')
                    ->label('合計')
                    ->weight('bold')
                    ->state(function (Get $get) {
                        $items = collect($get('items'));

                        return sprintf(
                            '$%s，約 %d 分鐘',
                            number_format((int) $items->sum(fn ($item) => (int) ($item['price'] ?? 0))),
                            (int) $items->sum(fn ($item) => (int) ($item['duration_minutes'] ?? 0)),
                        );
                    }),
            ]),
            Section::make('美容紀錄')
                ->columns(2)
                ->collapsed(fn (string $operation) => $operation === 'create')
                ->schema([
                    Textarea::make('grooming_notes')->label('美容紀錄')->rows(3)->columnSpanFull(),
                    TextInput::make('weight_kg')->label('本次體重')->numeric()->minValue(0)->maxValue(200)->suffix('公斤'),
                    PhotoUpload::make('before_photos', 'appointments')->label('美容前照片')->multiple()->maxFiles(6)->columnStart(1),
                    PhotoUpload::make('after_photos', 'appointments')->label('完成照片')->multiple()->maxFiles(6),
                ]),
            Section::make('結帳資訊')
                ->columns(2)
                ->visible(fn (?Appointment $record) => $record?->status === AppointmentStatus::Completed)
                ->schema([
                    TextInput::make('discount')->label('折扣金額')->numeric()->integer()->minValue(0)->prefix('$')->required(),
                    Select::make('payment_method')->label('付款方式')->options(PaymentMethod::class),
                    DateTimePicker::make('paid_at')->label('付款時間')->seconds(false)->required(),
                ]),
        ]);
    }

    private static function petSelect(): Select
    {
        return Select::make('pet_id')
            ->label('寵物')
            ->searchable()
            ->searchPrompt('輸入寵物名、飼主姓名或電話搜尋')
            ->getSearchResultsUsing(fn (string $search) => Pet::with('customer')
                ->where('is_active', true)
                ->search($search)
                ->limit(20)
                ->get()
                ->mapWithKeys(fn (Pet $pet) => [$pet->getKey() => $pet->label]))
            ->getOptionLabelUsing(fn ($value) => Pet::with('customer')->find($value)?->label)
            ->default(fn () => request()->integer('pet_id') ?: null)
            ->createOptionForm(PetResource::fields(withCustomer: true))
            ->createOptionUsing(fn (array $data) => Pet::create($data)->getKey())
            ->createOptionModalHeading('新增寵物與客戶')
            ->required()
            ->live()
            ->afterStateUpdated(function (Get $get, Set $set) {
                $set('package_id', null);
                self::repriceItems($get, $set);
            })
            ->columnSpanFull();
    }

    /** Choosing nothing means a one-off visit; a new package can be sold right here. */
    private static function packageSelect(): Select
    {
        return Select::make('package_id')
            ->label('包月方案')
            ->placeholder('單次消費（不使用方案）')
            ->options(fn (Get $get, ?Appointment $record) => Package::query()
                ->whereHas('pets', fn (Builder $query) => $query->whereKey($get('pet_id') ?: 0))
                ->whereNull('cancelled_at')
                ->with('pets')
                ->get()
                ->filter(fn (Package $package) => $package->getKey() === $record?->package_id
                    || ($package->expires_on->gte(today()) && $package->availableVisits((int) $get('pet_id')) > 0))
                ->mapWithKeys(fn (Package $package) => [$package->getKey() => $package->label((int) $get('pet_id'))]))
            ->default(fn () => request()->integer('package_id') ?: null)
            ->visible(fn (Get $get) => filled($get('pet_id')))
            ->createOptionForm(PackageResource::saleFields(
                customerId: fn ($livewire) => Pet::find($livewire->data['pet_id'] ?? null)?->customer_id,
                defaultPetIds: fn ($livewire) => array_filter([$livewire->data['pet_id'] ?? null]),
            ))
            ->createOptionUsing(fn (array $data) => Package::sell($data)->getKey())
            ->createOptionModalHeading('購買包月方案')
            ->live()
            ->afterStateUpdated(fn (Get $get, Set $set) => self::repriceItems($get, $set))
            ->rules([fn (Get $get, $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                $package = Package::with('pets')->find($value);
                $petId = (int) $get('pet_id');

                if (! $package?->hasPet($petId)) {
                    $fail('這個包月方案不包含這隻寵物。');

                    return;
                }

                if ($package->getKey() === $record?->package_id && $record->status === AppointmentStatus::Completed) {
                    return;
                }

                if ($problem = $package->bookingProblem($petId, Carbon::parse($get('starts_at')), $record?->getKey())) {
                    $fail($problem);

                    return;
                }

                $standardIds = Service::where('type', ServiceType::Standard)->pluck('id')->all();

                if (! collect($get('items'))->contains(fn ($item) => in_array((int) ($item['service_id'] ?? 0), $standardIds, true))) {
                    $fail('使用包月要選一項主服務。');
                }
            }])
            ->columnSpanFull();
    }

    /**
     * An appointment item priced for this pet and package. Services the package paid for
     * cost nothing; choosing a different main service charges only the difference.
     *
     * @return array<string, mixed>
     */
    public static function itemState(Service $service, $petId, $packageId): array
    {
        $pet = Pet::find($petId);
        $package = Package::with('pets')->find($packageId);
        $price = $pet?->priceFor($service) ?? $service->priceFor(null);

        if ($package?->covers($service->getKey())) {
            $price = 0;
        } elseif ($package && $pet && $service->type === ServiceType::Standard && $package->hasPet($pet)) {
            $price = max(0, $price - $package->unitPriceFor($pet));
        }

        return [
            'service_id' => $service->getKey(),
            'name' => $service->name,
            'price' => $price,
            'duration_minutes' => $service->duration_minutes,
        ];
    }

    /**
     * Prices follow the pet and its package. Using a package brings in its main service
     * (unless an upgrade is already chosen) and the add-ons it paid for.
     */
    private static function repriceItems(Get $get, Set $set): void
    {
        $petId = $get('pet_id');
        $packageId = $get('package_id');
        $items = collect($get('items') ?? []);
        $package = Package::find($packageId);

        if ($package) {
            $standardIds = Service::where('type', ServiceType::Standard)->pluck('id')->all();
            $chosen = $items->pluck('service_id')->map(fn ($id) => (int) $id);

            $wanted = collect($package->addonIds());

            if ($chosen->intersect($standardIds)->isEmpty()) {
                $wanted->prepend($package->service_id);
            }

            foreach ($wanted->diff($chosen) as $serviceId) {
                $items->put((string) Str::uuid(), ['service_id' => $serviceId]);
            }
        }

        $set('items', $items->map(function (array $item) use ($petId, $packageId) {
            $service = Service::find($item['service_id'] ?? null);

            return $service
                ? [...$item, ...self::itemState($service, $petId, $packageId), 'duration_minutes' => $item['duration_minutes'] ?? $service->duration_minutes]
                : $item;
        })->all());
    }

    private static function conflictRule(Get $get, $record): Closure
    {
        return function (string $attribute, $value, Closure $fail) use ($get, $record) {
            if (blank($get('groomer_id'))) {
                return;
            }

            $minutes = (int) collect($get('items'))->sum(fn ($item) => (int) ($item['duration_minutes'] ?? 0));
            $start = Carbon::parse($value);
            $end = $start->copy()->addMinutes($minutes ?: Appointment::DEFAULT_DURATION_MINUTES);

            if (TimeOff::groomerAwayOn((int) $get('groomer_id'), $start)) {
                $fail('這位美容師當天請假。');

                return;
            }

            if (Appointment::hasConflict((int) $get('groomer_id'), $start, $end, $record?->getKey())) {
                $fail('這位美容師在這段時間已經有其他預約。');
            }
        };
    }

    /** The date comes from the schedule page's "add" link; otherwise the next full hour. */
    private static function defaultStart(): Carbon
    {
        $date = (string) request()->query('date');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false
            ? Carbon::parse($date)->setTime(10, 0)
            : now()->addHour()->startOfHour();
    }

    public static function table(Table $table): Table
    {
        return self::baseTable($table)
            ->headerActions([
                ExcelExport::action('預約資料', fn (Appointment $appointment) => [
                    '時間' => $appointment->starts_at->format('Y/m/d H:i'),
                    '飼主' => $appointment->pet->customer->name,
                    '電話' => $appointment->pet->customer->phone,
                    '寵物' => $appointment->pet->name,
                    '服務' => $appointment->items->pluck('name')->implode('、'),
                    '類型' => $appointment->package_id ? '包月到店' : '單次消費',
                    '美容師' => $appointment->groomer?->name,
                    '狀態' => $appointment->status->getLabel(),
                    '小計' => $appointment->subtotal,
                    '折扣' => $appointment->discount,
                    '實收' => $appointment->total,
                    '付款方式' => $appointment->payment_method?->getLabel(),
                    '結帳時間' => $appointment->paid_at?->format('Y/m/d H:i'),
                ]),
            ])
            ->filters([
                SelectFilter::make('status')->label('狀態')->options(AppointmentStatus::class),
                SelectFilter::make('groomer_id')->label('美容師')->relationship('groomer', 'name'),
            ])
            ->recordActions(AppointmentActions::all());
    }

    /** Columns shared with the dashboard's list of today's appointments. */
    public static function baseTable(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['pet.customer', 'items', 'groomer']))
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('starts_at')->label('時間')->dateTime('m/d H:i')->sortable(),
                TextColumn::make('pet.name')
                    ->label('寵物')
                    ->weight('bold')
                    ->description(fn (Appointment $record) => implode('、', $record->pet->warnings()) ?: null)
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->whereHas('pet', fn (Builder $query) => $query->search($search))),
                TextColumn::make('pet.customer.name')
                    ->label('飼主')
                    ->description(fn (Appointment $record) => $record->pet->customer->phone)
                    ->url(fn (Appointment $record) => "tel:{$record->pet->customer->phone}"),
                TextColumn::make('items.name')->label('服務')->badge()->color('gray'),
                TextColumn::make('groomer.name')->label('美容師')->placeholder('未指定'),
                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->description(fn (Appointment $record) => match (true) {
                        $record->awaitsConfirmation() => '線上・待確認',
                        $record->source === Appointment::SOURCE_ONLINE => '線上預約',
                        default => null,
                    }),
                TextColumn::make('total')
                    ->label('金額')
                    ->prefix('$')
                    ->numeric(0)
                    ->description(fn (Appointment $record) => $record->package_id ? '包月' : null),
            ])
            ->searchPlaceholder('搜尋寵物名、飼主姓名或電話');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppointments::route('/'),
            'create' => CreateAppointment::route('/create'),
            'edit' => EditAppointment::route('/{record}/edit'),
        ];
    }
}
