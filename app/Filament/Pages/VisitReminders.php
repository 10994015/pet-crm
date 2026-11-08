<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Widgets\PackagesNeedingAttention;
use App\Models\Appointment;
use App\Models\Package;
use App\Models\Pet;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class VisitReminders extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.visit-reminders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Bell;

    protected static ?string $navigationLabel = '訊息通知';

    protected static ?string $title = '訊息通知';

    protected static ?int $navigationSort = 70;

    public static function getNavigationBadge(): ?string
    {
        return (string) self::pendingCount() ?: null;
    }

    public static function pendingCount(): int
    {
        return Pet::dueForReminder()->count() + count(Package::needingAttention()) + Appointment::awaitingConfirmation()->count();
    }

    protected function getHeaderActions(): array
    {
        $awaiting = Appointment::awaitingConfirmation()->count();

        return [
            Action::make('onlineBookings')
                ->label("線上預約待確認（{$awaiting}）")
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->color('warning')
                ->visible($awaiting > 0)
                ->url(AppointmentResource::getUrl('index', ['tab' => 'online'])),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [PackagesNeedingAttention::class];
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(Pet::dueForReminder()->with('customer'))
            ->defaultSort('next_visit_due_on')
            ->description('已到或快到建議回訪日、目前沒有預約的寵物。按下「已聯絡」後，'.Pet::CONTACT_COOLDOWN_DAYS.' 天內不會再列出。')
            ->emptyStateHeading('目前沒有需要聯絡的客戶')
            ->columns([
                TextColumn::make('name')->label('寵物')->weight('bold'),
                TextColumn::make('customer.name')->label('飼主'),
                TextColumn::make('customer.phone')
                    ->label('電話')
                    ->color('primary')
                    ->url(fn (Pet $record) => "tel:{$record->customer->phone}"),
                TextColumn::make('next_visit_due_on')
                    ->label('建議回訪日')
                    ->date('Y/m/d')
                    ->color(fn (Pet $record) => $record->next_visit_due_on->isPast() ? 'danger' : null)
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('line')
                    ->label('LINE 傳送')
                    ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                    ->url(fn (Pet $record) => 'https://line.me/R/share?text='.rawurlencode($record->reminderMessage()))
                    ->openUrlInNewTab(),
                Action::make('contacted')
                    ->label('已聯絡')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->action(fn (Pet $record) => $record->update(['last_contacted_at' => now()])),
                Action::make('book')
                    ->label('預約')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->url(fn (Pet $record) => AppointmentResource::getUrl('create', ['pet_id' => $record->getKey()])),
            ]);
    }
}
