<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\DailySchedule;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Services\DashboardMetrics;
use Filament\Widgets\Widget;

/** The big trend chart drawn on the dark band at the top of the dashboard. */
class RevenueBand extends Widget
{
    protected string $view = 'filament.widgets.revenue-band';

    protected static bool $isLazy = false;

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    /** @return array{title: string, prefix: string, months: list<array{label: string, value: int}>} */
    public function chart(): array
    {
        // Staff see how busy the shop is rather than its takings.
        return auth()->user()->isOwner()
            ? ['title' => '營收趨勢', 'prefix' => '$', 'months' => DashboardMetrics::monthly(DashboardMetrics::revenue(...))]
            : ['title' => '每月預約數', 'prefix' => '', 'months' => DashboardMetrics::monthly(DashboardMetrics::bookings(...))];
    }

    /** @return list<array{label: string, icon: string, url: string}> */
    public function quickActions(): array
    {
        return [
            ['label' => '新增預約', 'icon' => 'heroicon-m-plus', 'url' => AppointmentResource::getUrl('create')],
            ['label' => '新增客戶', 'icon' => 'heroicon-m-user-plus', 'url' => CustomerResource::getUrl('create')],
            ['label' => '行程表', 'icon' => 'heroicon-m-clock', 'url' => DailySchedule::getUrl()],
        ];
    }
}
