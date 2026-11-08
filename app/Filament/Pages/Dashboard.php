<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\PackagesNeedingAttention;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    public static function getNavigationLabel(): string|Htmlable
    {
        return '首頁';
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::Home;
    }

    public function getTitle(): string|Htmlable
    {
        return '首頁';
    }

    public function getWidgets(): array
    {
        return array_values(array_diff(parent::getWidgets(), [PackagesNeedingAttention::class]));
    }

    public function getColumns(): int|array
    {
        return ['lg' => 12];
    }
}
