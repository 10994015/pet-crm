<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PetGender: string implements HasLabel
{
    case Male = 'male';
    case Female = 'female';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Male => '公',
            self::Female => '母',
            self::Unknown => '不確定',
        };
    }
}
