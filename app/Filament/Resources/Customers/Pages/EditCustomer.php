<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            self::activationCodeAction(),
            ActionGroup::make([
                self::closeMembershipAction(),
                DeleteAction::make(),
            ]),
        ];
    }

    /** Hands the owner a one-off code for opening, or resetting, their website account. */
    public static function activationCodeAction(): Action
    {
        return Action::make('activationCode')
            ->label(fn (Customer $record) => $record->isMember() ? '重設會員密碼' : '會員開通碼')
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->disabled(fn (Customer $record) => blank($record->phone))
            ->tooltip(fn (Customer $record) => blank($record->phone) ? '請先填寫電話，客人用電話登入' : null)
            ->requiresConfirmation()
            ->modalHeading(fn (Customer $record) => $record->isMember() ? '產生重設密碼用的開通碼？' : '產生會員開通碼？')
            ->modalDescription(fn (Customer $record) => '請先確認是客人本人。開通碼 '.Customer::ACTIVATION_DAYS.' 天內有效，只會顯示這一次；客人到網站「會員中心 → 用開通碼設定密碼」，輸入電話 '
                .$record->phone.' 和開通碼即可。'.($record->isMember() ? '舊密碼會在客人設好新密碼後失效。' : ''))
            ->modalSubmitActionLabel('產生開通碼')
            ->action(function (Customer $record) {
                $code = $record->issueActivationCode();
                $message = "{$record->name} 您好，您的 ".config('app.name')." 會員開通碼是 {$code}（".Customer::ACTIVATION_DAYS.' 天內有效）。請到 '
                    .route('front.member.activate').' 輸入手機號碼和開通碼，設定密碼後就能查看毛孩的美容紀錄與預約。';

                Notification::make()
                    ->title("開通碼：{$code}")
                    ->body('請告訴客人或用 LINE 傳給客人。關掉這則通知後就看不到了，需要時再重新產生即可。')
                    ->success()
                    ->persistent()
                    ->actions([
                        Action::make('line')
                            ->label('用 LINE 傳給客人')
                            ->url('https://line.me/R/share?text='.rawurlencode($message))
                            ->openUrlInNewTab(),
                    ])
                    ->send();
            });
    }

    public static function closeMembershipAction(): Action
    {
        return Action::make('closeMembership')
            ->label('停用會員帳號')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Customer $record) => $record->isMember())
            ->requiresConfirmation()
            ->modalDescription('客人會被登出，也不能再登入網站的會員中心（例如手機遺失或換人使用）。客戶資料與消費紀錄都會保留，之後可以再發開通碼重新開通。')
            ->action(function (Customer $record) {
                $record->closeMembership();

                Notification::make()->title('已停用會員帳號')->success()->send();
            });
    }
}
