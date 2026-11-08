<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Filament\Pages\VisitReminders;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Appointment;
use App\Models\Customer;
use App\Services\Revenue;
use Filament\Widgets\Widget;

/** The row of today's numbers that overlaps the bottom of the dark band. */
class ShopStrip extends Widget
{
    protected string $view = 'filament.widgets.shop-strip';

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    /** @return list<array{label: string, value: string, icon: string, color: string, url: ?string}> */
    public function stats(): array
    {
        $today = Appointment::whereDate('starts_at', today())
            ->whereNotIn('status', [AppointmentStatus::Cancelled, AppointmentStatus::NoShow]);

        $middle = auth()->user()->isOwner()
            ? ['label' => '今日營收', 'value' => '$'.number_format(Revenue::total(today()->startOfDay(), today()->endOfDay())), 'icon' => 'heroicon-o-banknotes', 'color' => 'green', 'url' => null]
            : ['label' => '尚未完成', 'value' => number_format((clone $today)->whereIn('status', AppointmentStatus::active())->count()), 'icon' => 'heroicon-o-scissors', 'color' => 'green', 'url' => AppointmentResource::getUrl('index')];

        return [
            ['label' => '今日預約', 'value' => number_format((clone $today)->count()), 'icon' => 'heroicon-o-calendar-days', 'color' => 'orange', 'url' => AppointmentResource::getUrl('index')],
            $middle,
            ['label' => '客戶', 'value' => number_format(Customer::count()), 'icon' => 'heroicon-o-users', 'color' => 'blue', 'url' => CustomerResource::getUrl('index')],
            ['label' => '待處理通知', 'value' => number_format(VisitReminders::pendingCount()), 'icon' => 'heroicon-o-bell-alert', 'color' => 'red', 'url' => VisitReminders::getUrl()],
        ];
    }
}
