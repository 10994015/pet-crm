<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ServiceType: string implements HasColor, HasLabel
{
    case Standard = 'standard';
    case Optional = 'optional';

    public function getLabel(): string
    {
        return match ($this) {
            self::Standard => '主服務',
            self::Optional => '加購',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Standard => 'primary',
            self::Optional => 'gray',
        };
    }
}
