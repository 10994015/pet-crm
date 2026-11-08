<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\Setting;
use App\Services\Backup;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Throwable;

class ShopSettings extends Page
{
    protected string $view = 'filament.pages.shop-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Cog6Tooth;

    protected static ?string $navigationLabel = '系統設定';

    protected static ?string $title = '系統設定';

    protected static ?int $navigationSort = 80;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            ...Setting::current(),
            ...Setting::texts(),
            'booking_enabled' => Setting::value('booking_enabled') >= 1,
            'booking_closed_days' => array_map('strval', Setting::closedWeekdays()),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('包月方案')
                    ->description('修改後只影響之後賣出的方案，已賣出的維持當時條件。')
                    ->columns(2)
                    ->schema([
                        TextInput::make('package_discount')
                            ->label('折數')
                            ->helperText('9 = 九折、8.5 = 85 折、10 = 不打折')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10)
                            ->step(0.1)
                            ->suffix('折')
                            ->required()
                            ->live(onBlur: true),
                        TextInput::make('package_visits')
                            ->label('包含次數')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100)
                            ->suffix('次')
                            ->required()
                            ->live(onBlur: true),
                        TextInput::make('package_valid_months')
                            ->label('有效期限')
                            ->helperText('到期後剩餘次數會保留，但要延期才能再預約')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(60)
                            ->suffix('個月')
                            ->required(),
                        TextInput::make('package_expiry_notice_days')
                            ->label('到期前提醒')
                            ->helperText('會出現在訊息通知')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(90)
                            ->suffix('天前')
                            ->required(),
                        TextEntry::make('example')
                            ->label('試算')
                            ->columnSpanFull()
                            ->state(function (Get $get) {
                                $visits = (int) $get('package_visits');
                                $rate = (float) $get('package_discount');

                                return "單次 \$500 的服務：\$500 × {$visits} 次 × {$rate} 折 = \$".number_format(500 * $visits * $rate / 10);
                            }),
                    ]),
                Section::make('線上預約')
                    ->description(new HtmlString('客人在 <a href="'.route('front.home').'" target="_blank" class="underline">預約網站</a> 預約，送出後會出現在預約清單，標示「線上・待確認」。同一時段可接的數量等於當天上班的美容師人數。'))
                    ->columns(3)
                    ->schema([
                        Toggle::make('booking_enabled')->label('開放線上預約')->columnSpanFull(),
                        TextInput::make('booking_open_hour')
                            ->label('第一個時段')
                            ->helperText('10 = 10:00、9.5 = 9:30')
                            ->numeric()->minValue(0)->maxValue(23.5)->step(0.5)->suffix('點')->required(),
                        TextInput::make('booking_close_hour')
                            ->label('最晚結束')
                            ->helperText('美容要在這之前做完')
                            ->numeric()->minValue(1)->maxValue(24)->step(0.5)->suffix('點')->required()
                            ->gt('booking_open_hour'),
                        Select::make('booking_slot_minutes')
                            ->label('時段間隔')
                            ->options([15 => '15 分鐘', 30 => '30 分鐘', 60 => '1 小時'])
                            ->required(),
                        TextInput::make('booking_days_ahead')
                            ->label('可以預約多久以後')
                            ->numeric()->integer()->minValue(1)->maxValue(180)->suffix('天內')->required(),
                        TextInput::make('booking_lead_hours')
                            ->label('最晚要提前')
                            ->numeric()->integer()->minValue(0)->maxValue(72)->suffix('小時')->required(),
                        TextInput::make('member_cancel_hours')
                            ->label('會員自行取消')
                            ->helperText('會員可在網站上取消預約，最晚到預約前幾小時；0 表示不開放')
                            ->numeric()->integer()->minValue(0)->maxValue(168)->suffix('小時前')->required(),
                        TextInput::make('booking_max_no_shows')
                            ->label('連續未到幾次要來電')
                            ->helperText('達到後這位客人不能線上預約，之後來店一次就會恢復；0 表示不限制')
                            ->numeric()->integer()->minValue(0)->maxValue(20)->suffix('次')->required(),
                        TextInput::make('booking_max_pending')
                            ->label('待確認預約上限')
                            ->helperText('待確認累積到這個數量時，新客人要改用電話預約，防止大量假預約佔滿時段；0 表示不限制')
                            ->numeric()->integer()->minValue(0)->maxValue(500)->suffix('筆')->required(),
                        CheckboxList::make('booking_closed_days')
                            ->label('每週公休日')
                            ->helperText('過年或臨時休假請到「店休與請假」設定。')
                            ->options(['1' => '週一', '2' => '週二', '3' => '週三', '4' => '週四', '5' => '週五', '6' => '週六', '0' => '週日'])
                            ->columns(7)
                            ->columnSpanFull(),
                        Textarea::make('booking_notice')
                            ->label('預約頁公告')
                            ->placeholder('例如：夏季洗澡預約較滿，建議提早預約')
                            ->maxLength(250)
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('店家資訊')
                    ->description('顯示在預約網站上，方便客人聯絡。')
                    ->columns(3)
                    ->schema([
                        TextInput::make('shop_phone')->label('電話')->tel()->maxLength(30),
                        TextInput::make('shop_line')->label('LINE ID')->maxLength(60),
                        TextInput::make('shop_address')->label('地址')->maxLength(200),
                    ]),
                Section::make('客戶')->columns(2)->schema([
                    TextInput::make('vip_spending')
                        ->label('VIP 門檻')
                        ->helperText('累計消費達到這個金額就標示為 VIP')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->prefix('$')
                        ->required(),
                ]),
                Section::make('資料備份')
                    ->description('每天凌晨 3 點自動備份資料庫和上傳的照片。備份檔包含客戶個資，下載後請妥善保管，並存一份到主機以外的地方。')
                    ->schema([
                        TextEntry::make('last_backup')
                            ->label('最新備份')
                            ->state(fn () => self::lastBackupLabel())
                            ->color(fn () => self::backupIsRecent() ? 'success' : 'danger'),
                    ]),
            ]);
    }

    private static function lastBackupLabel(): string
    {
        $file = app(Backup::class)->latest();

        if (! $file) {
            return '還沒有備份，請按上方的「立即備份」';
        }

        $at = Carbon::createFromTimestamp($file->getMTime(), config('app.timezone'));

        return $at->format('Y/m/d H:i').'（'.Number::fileSize($file->getSize(), 1).'）'.(self::backupIsRecent() ? '' : '：已超過兩天沒有新備份，請檢查排程');
    }

    private static function backupIsRecent(): bool
    {
        $file = app(Backup::class)->latest();

        return $file && Carbon::createFromTimestamp($file->getMTime())->gt(now()->subDays(2));
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $state['booking_enabled'] = $state['booking_enabled'] ? 1 : 0;
        $state['booking_closed_days'] = $state['booking_closed_days'] ?? [];

        Setting::store($state);

        Notification::make()->title('設定已儲存')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backupNow')
                ->label('立即備份')
                ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                ->color('gray')
                ->action(function (Backup $backup) {
                    try {
                        $backup->create();
                        $backup->prune((int) config('backup.keep_days'));
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()->title('備份失敗')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('備份完成')->success()->send();
                }),
            Action::make('downloadBackup')
                ->label('下載最新備份')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => app(Backup::class)->latest() !== null)
                ->url(fn () => route('admin.backups.latest')),
            Action::make('users')
                ->label('員工帳號')
                ->icon(Heroicon::OutlinedIdentification)
                ->color('gray')
                ->url(UserResource::getUrl('index')),
        ];
    }
}
