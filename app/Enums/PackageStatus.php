<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PackageStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case UsedUp = 'used_up';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => '使用中',
            self::UsedUp => '已用完',
            self::Expired => '已逾期',
            self::Cancelled => '已終止',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::UsedUp => 'gray',
            self::Expired => 'danger',
            self::Cancelled => 'gray',
        };
    }
}
