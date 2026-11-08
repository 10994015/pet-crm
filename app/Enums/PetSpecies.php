<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PetSpecies: string implements HasLabel
{
    case Dog = 'dog';
    case Cat = 'cat';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dog => '狗',
            self::Cat => '貓',
            self::Other => '其他',
        };
    }
}
