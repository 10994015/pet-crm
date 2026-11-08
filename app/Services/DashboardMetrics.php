<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Customer;
use Closure;
use Illuminate\Support\Carbon;

/** The month-by-month figures behind the dashboard. */
class DashboardMetrics
{
    public static function bookings(Carbon $from, Carbon $to): int
    {
        return Appointment::query()
            ->whereBetween('starts_at', [$from, $to])
            ->where('status', '!=', AppointmentStatus::Cancelled)
            ->count();
    }

    public static function newCustomers(Carbon $from, Carbon $to): int
    {
        return Customer::whereBetween('created_at', [$from, $to])->count();
    }

    public static function revenue(Carbon $from, Carbon $to): int
    {
        return Revenue::total($from, $to);
    }

    public static function customers(Carbon $from, Carbon $to): int
    {
        return Customer::where('created_at', '<=', $to)->count();
    }

    /**
     * Whole-month values for the last few months, oldest first, so the current month
     * includes appointments still to come.
     *
     * @return list<array{label: string, value: int}>
     */
    public static function monthly(Closure $measure, int $months = 12): array
    {
        $thisMonth = now()->startOfMonth();

        return collect(range($months - 1, 0))
            ->map(fn (int $ago) => $thisMonth->copy()->subMonthsNoOverflow($ago))
            ->map(fn (Carbon $start) => [
                'label' => $start->month.'月',
                'value' => (int) $measure($start->copy(), $start->copy()->endOfMonth()),
            ])
            ->all();
    }

    /**
     * Compares this month up to today with the same days of last month, so the arrow
     * isn't always pointing down early in a month.
     *
     * @return array{label: string, up: bool}
     */
    public static function trend(Closure $measure, bool $cumulative = false): array
    {
        $monthStart = now()->startOfMonth();
        $current = (int) $measure($monthStart->copy(), now()->endOfDay());
        $previous = (int) ($cumulative
            ? $measure($monthStart->copy(), $monthStart->copy()->subSecond())
            : $measure($monthStart->copy()->subMonthNoOverflow(), now()->subMonthNoOverflow()->endOfDay()));

        if ($previous === 0) {
            return ['label' => $current > 0 ? '↑ 新增' : '持平', 'up' => true];
        }

        $change = (int) round(($current - $previous) / $previous * 100);

        return [
            'label' => match (true) {
                $change > 0 => "↑ {$change}%",
                $change < 0 => '↓ '.abs($change).'%',
                default => '持平',
            },
            'up' => $change >= 0,
        ];
    }
}
