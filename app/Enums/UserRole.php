<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel
{
    case Owner = 'owner';
    case Staff = 'staff';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => '業主',
            self::Staff => '員工',
        };
    }
}
