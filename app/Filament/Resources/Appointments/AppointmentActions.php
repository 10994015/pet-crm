<?php

namespace App\Filament\Resources\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Filament\Support\PhotoUpload;
use App\Models\Appointment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** The steps of an appointment, shared by the appointment list and the dashboard. */
class AppointmentActions
{
    /** @return array<int, Action|ActionGroup> */
    public static function all(): array
    {
        return [
            self::confirm(),
            self::statusAction('checkIn', '報到', Heroicon::OutlinedHandRaised, AppointmentStatus::CheckedIn, [AppointmentStatus::Scheduled]),
            self::statusAction('start', '開始美容', Heroicon::OutlinedScissors, AppointmentStatus::InProgress, [AppointmentStatus::CheckedIn]),
            self::checkout(),
            ActionGroup::make([
                EditAction::make()->url(fn (Appointment $record) => AppointmentResource::getUrl('edit', ['record' => $record])),
                Action::make('remind')
                    ->label('LINE 傳送提醒')
                    ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                    ->visible(fn (Appointment $record) => $record->status === AppointmentStatus::Scheduled)
                    ->url(fn (Appointment $record) => 'https://line.me/R/share?text='.rawurlencode($record->reminderMessage()))
                    ->openUrlInNewTab(),
                self::statusAction('noShow', '標記未到', Heroicon::OutlinedUserMinus, AppointmentStatus::NoShow, [AppointmentStatus::Scheduled])
                    ->color('danger')
                    ->requiresConfirmation(),
                self::statusAction('cancel', '取消預約', Heroicon::OutlinedXCircle, AppointmentStatus::Cancelled, AppointmentStatus::active())
                    ->color('danger')
                    ->requiresConfirmation(),
                self::reopen(),
            ]),
        ];
    }

    /** The same steps in one menu, for narrow lists. */
    public static function grouped(): ActionGroup
    {
        return ActionGroup::make(array_merge(...array_map(
            fn (Action|ActionGroup $action) => $action instanceof ActionGroup ? $action->getActions() : [$action],
            self::all(),
        )));
    }

    /** Marks a website booking as seen, after the shop has checked the time works. */
    public static function confirm(): Action
    {
        return Action::make('confirm')
            ->label('確認')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('warning')
            ->visible(fn (Appointment $record) => $record->awaitsConfirmation())
            ->requiresConfirmation()
            ->modalHeading('確認這筆線上預約？')
            ->modalDescription(fn (Appointment $record) => "{$record->pet->customer->name}（{$record->pet->customer->phone}）幫 {$record->pet->name} 約了 "
                .$record->starts_at->locale('zh_TW')->isoFormat('M/D（dd）HH:mm').'。確認後客人查詢時會看到「已確認」。時間不行的話，請先打給客人再改時間或取消。')
            ->modalSubmitActionLabel('確認預約')
            ->action(function (Appointment $record) {
                $record->update(['confirmed_at' => now()]);

                Notification::make()->title('已確認預約')->success()->send();
            });
    }

    /** @param  list<AppointmentStatus>  $from */
    private static function statusAction(string $name, string $label, Heroicon $icon, AppointmentStatus $to, array $from): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->visible(fn (Appointment $record) => in_array($record->status, $from, true))
            ->action(function (Appointment $record) use ($to, $from) {
                abort_unless(in_array($record->status, $from, true), 409);
                $record->update(['status' => $to]);
            });
    }

    private static function checkout(): Action
    {
        return Action::make('checkout')
            ->label('完成結帳')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Appointment $record) => $record->status->isActive())
            ->modalHeading(fn (Appointment $record) => "{$record->pet->name} 完成結帳")
            ->modalSubmitActionLabel('確認結帳')
            ->fillForm(fn (Appointment $record) => [
                'discount' => $record->discount,
                'payment_method' => PaymentMethod::Cash,
                'grooming_notes' => $record->grooming_notes,
                'weight_kg' => $record->weight_kg ?? $record->pet->weight_kg,
                'after_photos' => $record->after_photos,
                'paid_at' => now(),
            ])
            ->schema([
                TextEntry::make('services')
                    ->label('服務內容')
                    ->state(fn (Appointment $record) => $record->items
                        ->map(fn ($item) => "{$item->name} \${$item->price}")
                        ->implode('、')),
                TextEntry::make('package')
                    ->label('包月方案')
                    ->visible(fn (Appointment $record) => $record->package_id !== null)
                    ->state(fn (Appointment $record) => "{$record->package->contents()}，結帳後扣 1 次，{$record->pet->name} 剩 ".max(0, $record->package->remainingVisits($record->pet_id) - 1).' 次'),
                TextEntry::make('subtotal')->label('小計')->state(fn (Appointment $record) => '$'.number_format($record->subtotal)),
                TextInput::make('discount')
                    ->label('折扣金額')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(fn (Appointment $record) => $record->subtotal)
                    ->default(0)
                    ->prefix('$')
                    ->required(),
                Select::make('payment_method')
                    ->label('付款方式')
                    ->options(PaymentMethod::class)
                    ->required(fn (Appointment $record) => $record->subtotal > 0),
                DateTimePicker::make('paid_at')
                    ->label('付款時間')
                    ->helperText('補登過去的消費時，營收會算在這一天')
                    ->seconds(false)
                    ->maxDate(now()->endOfDay())
                    ->required()
                    ->visible(fn () => auth()->user()->isOwner()),
                Textarea::make('grooming_notes')->label('美容紀錄')->placeholder('例如：皮膚有點紅、下次留長一點')->rows(3),
                TextInput::make('weight_kg')->label('本次體重')->numeric()->minValue(0)->maxValue(200)->suffix('公斤'),
                PhotoUpload::make('after_photos', 'appointments')->label('完成照片')->multiple()->maxFiles(6),
            ])
            ->action(function (Appointment $record, array $data) {
                abort_unless($record->status->isActive(), 409);
                $record->checkout($data);

                Notification::make()
                    ->title('已完成結帳，實收 $'.number_format($record->total))
                    ->success()
                    ->send();
            });
    }

    private static function reopen(): Action
    {
        return Action::make('reopen')
            ->label(fn (Appointment $record) => $record->status === AppointmentStatus::Completed ? '撤銷結帳' : '恢復預約')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->requiresConfirmation()
            ->modalDescription(fn (Appointment $record) => $record->status === AppointmentStatus::Completed
                ? '這筆金額會從營收中移除，預約回到「已預約」狀態。'
                : '預約會回到「已預約」狀態。')
            ->visible(fn (Appointment $record) => ! $record->status->isActive()
                && ($record->status !== AppointmentStatus::Completed || auth()->user()->isOwner()))
            ->action(function (Appointment $record) {
                abort_if($record->status->isActive(), 409);
                abort_if($record->status === AppointmentStatus::Completed && ! auth()->user()->isOwner(), 403);
                $record->reopen();
            });
    }
}
