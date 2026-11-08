<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case Cash = 'cash';
    case Card = 'card';
    case Transfer = 'transfer';
    case LinePay = 'line_pay';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => '現金',
            self::Card => '信用卡',
            self::Transfer => '轉帳',
            self::LinePay => 'LINE Pay',
            self::Other => '其他',
        };
    }
}
