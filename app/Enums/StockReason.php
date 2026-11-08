<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockReason: string implements HasLabel
{
    case Initial = 'initial';
    case Sale = 'sale';
    case OrderCorrection = 'order_correction';
    case Refund = 'refund';
    case Restock = 'restock';
    case Count = 'count';

    public function getLabel(): string
    {
        return match ($this) {
            self::Initial => '期初庫存',
            self::Sale => '銷售',
            self::OrderCorrection => '修改訂單',
            self::Refund => '退款退回',
            self::Restock => '進貨',
            self::Count => '盤點',
        };
    }
}
