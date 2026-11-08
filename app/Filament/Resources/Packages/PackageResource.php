<?php

namespace App\Filament\Resources\Packages;

use App\Enums\PackageStatus;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Packages\Pages\ManagePackages;
use App\Filament\Resources\Pets\PetResource;
use App\Filament\Support\ExcelExport;
use App\Models\Package;
use App\Models\Pet;
use App\Models\Service;
use App\Models\Setting;
use BackedEnum;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Ticket;

    protected static ?string $navigationLabel = '包月方案';

    protected static ?string $modelLabel = '包月方案';

    protected static ?string $pluralModelLabel = '包月方案';

    protected static ?int $navigationSort = 45;

    /**
     * What selling a package asks for; shared by this page and the appointment form.
     *
     * @param  Closure  $customerId  Finds the owner the package is sold to.
     * @param  Closure  $defaultPetIds  Dogs ticked to begin with.
     * @return array<int, mixed>
     */
    public static function saleFields(Closure $customerId, Closure $defaultPetIds): array
    {
        return [
            CheckboxList::make('pet_ids')
                ->label('包含的寵物')
                ->helperText('同一位飼主的多隻狗可以共用一個方案，每隻各自計次')
                ->options(fn (Get $get, $livewire) => Pet::query()
                    ->where('customer_id', $customerId($livewire, $get) ?: 0)
                    ->where('is_active', true)
                    ->pluck('name', 'id'))
                ->default($defaultPetIds)
                ->columns(3)
                ->required()
                ->live(),
            Select::make('service_id')
                ->label('主服務')
                ->options(fn () => Service::query()
                    ->where('type', ServiceType::Standard)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->pluck('name', 'id'))
                ->required()
                ->live(),
            CheckboxList::make('addon_ids')
                ->label('一起預付的加購')
                ->helperText('每次到店都會做的項目，例如刷牙，到店時就不另外收費')
                ->options(fn () => Service::query()
                    ->where('type', ServiceType::Optional)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->pluck('name', 'id'))
                ->columns(3)
                ->live(),
            TextEntry::make('quote')
                ->label('方案內容')
                ->state(function (Get $get) {
                    $settings = Setting::current();
                    $visits = (int) $settings['package_visits'];
                    $terms = "每隻 {$visits} 次、主服務".self::rateLabel($settings['package_discount'])
                        ."、加購原價、效期 {$settings['package_valid_months']} 個月";
                    $main = Service::find($get('service_id'));
                    $pets = Pet::whereKey($get('pet_ids') ?? [])->get();

                    if (! $main || $pets->isEmpty()) {
                        return $terms;
                    }

                    $addons = Service::whereKey($get('addon_ids') ?? [])->get();

                    return $terms.'，售價 $'.number_format(Package::quote($pets, $main, $addons, $visits, $settings['package_discount']));
                }),
            TextInput::make('price')
                ->label('特殊售價')
                ->helperText('留白就依上面的售價')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->prefix('$')
                ->visible(fn () => auth()->user()->isOwner()),
            Select::make('payment_method')->label('付款方式')->options(PaymentMethod::class)->default(PaymentMethod::Cash)->required(),
            DatePicker::make('starts_on')->label('開始日期')->default(today())->required(),
            Textarea::make('notes')->label('備註')->rows(2),
        ];
    }

    public static function rateLabel(float $rate): string
    {
        return $rate >= 10 ? '不打折' : rtrim(rtrim(number_format($rate, 1), '0'), '.').' 折';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            PetResource::customerSelect()->live(),
            ...self::saleFields(
                customerId: fn ($livewire, Get $get) => $get('customer_id'),
                defaultPetIds: fn () => [],
            ),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return self::columns($table)
            ->headerActions([
                ExcelExport::action('包月方案', fn (Package $package) => [
                    '飼主' => $package->customer->name,
                    '寵物與剩餘次數' => $package->visitSummary(),
                    '內容' => $package->contents(),
                    '狀態' => $package->status()->getLabel(),
                    '折數' => $package->discount_rate,
                    '售價' => $package->price,
                    '退款' => $package->refund_amount,
                    '開始日' => $package->starts_on->format('Y/m/d'),
                    '到期日' => $package->expires_on->format('Y/m/d'),
                    '備註' => $package->notes,
                ]),
            ])
            ->recordActions([
                self::bookAction(),
                ActionGroup::make([self::editAction(), self::adjustAction(), self::extendAction(), self::terminateAction()]),
            ]);
    }

    /**
     * Fixes a package's paperwork. Dogs, services and dates are left alone because
     * visits and prices already hang off them; those change through 修正次數 / 延期 / 終止.
     */
    public static function editAction(): Action
    {
        return Action::make('edit')
            ->label('編輯')
            ->icon(Heroicon::OutlinedPencil)
            ->visible(fn (Package $record) => auth()->user()->can('update', $record))
            ->modalHeading('編輯包月方案')
            ->modalDescription(fn (Package $record) => $record->customer->name.'：'.$record->contents()
                .'。寵物、服務和次數不能在這裡改，次數請用「修正次數」，到期日請用「延期」。')
            ->fillForm(fn (Package $record) => [
                'price' => $record->price,
                'payment_method' => $record->payment_method,
                'notes' => $record->notes,
            ])
            ->schema([
                TextInput::make('price')
                    ->label('售價')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->prefix('$')
                    ->required()
                    ->visible(fn () => auth()->user()->isOwner()),
                Select::make('payment_method')->label('付款方式')->options(PaymentMethod::class)->required(),
                Textarea::make('notes')->label('備註')->rows(3),
            ])
            ->action(function (Package $record, array $data) {
                $record->update($data);

                Notification::make()->title('已儲存')->success()->send();
            });
    }

    /** Columns shared with the customer and pet pages and the notifications page. */
    public static function columns(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'pets']))
            ->defaultSort('expires_on')
            ->columns([
                TextColumn::make('customer.name')
                    ->label('飼主')
                    ->weight('bold')
                    ->description(fn (Package $record) => $record->pets->pluck('name')->implode('、'))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                        ->whereHas('customer', fn (Builder $query) => $query->search($search))
                        ->orWhereHas('pets', fn (Builder $query) => $query->where('name', 'like', "%{$search}%")))),
                TextColumn::make('contents')->label('內容')->state(fn (Package $record) => $record->contents())->wrap(),
                TextColumn::make('status')->label('狀態')->badge()->state(fn (Package $record) => $record->status()),
                TextColumn::make('visits')->label('剩餘次數')->state(fn (Package $record) => $record->visitSummary())->wrap(),
                TextColumn::make('expires_on')->label('到期日')->date('Y/m/d')->sortable(),
                TextColumn::make('price')
                    ->label('售價')
                    ->prefix('$')
                    ->numeric(0)
                    ->description(fn (Package $record) => $record->refund_amount ? '已退 $'.number_format($record->refund_amount) : null),
            ])
            ->searchPlaceholder('搜尋飼主姓名、電話或寵物名');
    }

    public static function bookAction(): Action
    {
        return Action::make('book')
            ->label('用方案預約')
            ->tooltip('用這個包月方案幫狗狗約下一次')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->visible(fn (Package $record) => $record->status() === PackageStatus::Active)
            ->url(fn (Package $record) => AppointmentResource::getUrl('create', [
                'pet_id' => $record->petToBook()?->getKey(),
                'package_id' => $record->getKey(),
            ]));
    }

    /** Corrects a dog's used visits, for mistakes in the paper book or the old system. */
    public static function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label('修正次數')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (Package $record) => auth()->user()->can('update', $record) && $record->cancelled_at === null)
            ->fillForm(fn (Package $record) => [
                'pet_id' => $record->pets->first()?->getKey(),
                'used' => $record->usedVisits(),
            ])
            ->schema([
                Select::make('pet_id')
                    ->label('寵物')
                    ->options(fn (Package $record) => $record->pets->mapWithKeys(fn (Pet $pet) => [
                        $pet->getKey() => "{$pet->name}（目前已用 {$record->usedVisits($pet)} / {$record->totalVisits($pet)} 次）",
                    ]))
                    ->required(),
                TextInput::make('used')
                    ->label('正確的已用次數')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->required(),
                Textarea::make('reason')->label('修正原因')->placeholder('例如：紙本第 92 頁其實已用 2 次')->rows(2)->required(),
                TextEntry::make('history')
                    ->label('過去的修正紀錄')
                    ->state(fn (Package $record) => $record->adjustments()->with(['pet', 'user'])->limit(5)->get()
                        ->map(fn ($adjustment) => $adjustment->created_at->format('Y/m/d')." {$adjustment->pet->name}：{$adjustment->from_used} → {$adjustment->to_used} 次，"
                            .($adjustment->user?->name ?? '系統')."：{$adjustment->reason}")
                        ->implode("\n") ?: '還沒有修正過')
                    ->extraAttributes(['style' => 'white-space: pre-line']),
            ])
            ->action(function (Package $record, array $data, Action $action) {
                try {
                    $record->adjustUsedVisits((int) $data['pet_id'], (int) $data['used'], (string) $data['reason'], auth()->user());
                } catch (DomainException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('已修正次數並留下紀錄')->success()->send();
            });
    }

    public static function extendAction(): Action
    {
        return Action::make('extend')
            ->label('延期')
            ->icon(Heroicon::OutlinedCalendar)
            ->visible(fn (Package $record) => auth()->user()->can('update', $record) && $record->cancelled_at === null)
            ->modalDescription(fn (Package $record) => '目前到期日：'.$record->expires_on->format('Y/m/d').'。已逾期的方案會從今天起算。')
            ->schema([
                TextInput::make('months')->label('延長月數')->numeric()->integer()->minValue(1)->maxValue(24)->default(1)->required()->suffix('個月'),
            ])
            ->action(function (Package $record, array $data) {
                $record->extend((int) $data['months']);

                Notification::make()->title('已延期到 '.$record->expires_on->format('Y/m/d'))->success()->send();
            });
    }

    public static function terminateAction(): Action
    {
        return Action::make('terminate')
            ->label('終止方案')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Package $record) => auth()->user()->can('update', $record) && $record->cancelled_at === null)
            ->modalDescription(fn (Package $record) => "剩餘 {$record->totalRemaining()} 次將作廢。有退款就填金額，會從退款當天的營收扣除。")
            ->schema([
                TextInput::make('refund_amount')
                    ->label('退款金額')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(fn (Package $record) => $record->price)
                    ->default(0)
                    ->prefix('$')
                    ->required(),
                Textarea::make('note')->label('原因')->rows(2),
            ])
            ->action(function (Package $record, array $data, Action $action) {
                try {
                    $record->terminate((int) $data['refund_amount'], $data['note'] ?? null);
                } catch (DomainException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('方案已終止')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManagePackages::route('/')];
    }
}
