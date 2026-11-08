<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PetTemperament: string implements HasLabel
{
    case Gentle = 'gentle';
    case Shy = 'shy';
    case Nervous = 'nervous';
    case Bites = 'bites';

    public function getLabel(): string
    {
        return match ($this) {
            self::Gentle => '溫馴',
            self::Shy => '膽小',
            self::Nervous => '容易緊張',
            self::Bites => '會咬人',
        };
    }
}
