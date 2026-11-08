<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PetSize: string implements HasLabel
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';

    public function getLabel(): string
    {
        return match ($this) {
            self::Small => '小型',
            self::Medium => '中型',
            self::Large => '大型',
        };
    }
}
