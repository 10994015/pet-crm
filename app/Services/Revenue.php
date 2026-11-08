<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Package;
use App\Models\ProductOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The one place that decides what counts as money in.
 *
 * Grooming, package sales and product sales count on the day they were paid; refunds
 * are subtracted on the day they were made, so a closed month's figures never change.
 */
class Revenue
{
    public static function total(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) Appointment::whereBetween('paid_at', [$from, $to])->sum('total')
            + (int) Package::whereBetween('paid_at', [$from, $to])->sum('price')
            + (int) ProductOrder::whereBetween('paid_at', [$from, $to])->sum('total')
            - (int) Package::whereBetween('refunded_at', [$from, $to])->sum('refund_amount')
            - (int) ProductOrder::whereBetween('refunded_at', [$from, $to])->sum('total');
    }

    /**
     * @return Collection<int, array{date: string, grooming: int, grooming_count: int, packages: int, packages_count: int, products: int, products_count: int, refunds: int, total: int}>
     */
    public static function daily(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $days = [];

        $add = function (iterable $rows, string $dateColumn, string $amountColumn, string $bucket) use (&$days) {
            foreach ($rows as $row) {
                $date = $row->{$dateColumn}->toDateString();
                $days[$date] ??= [
                    'date' => $date, 'grooming' => 0, 'grooming_count' => 0, 'packages' => 0,
                    'packages_count' => 0, 'products' => 0, 'products_count' => 0, 'refunds' => 0,
                ];
                $days[$date][$bucket] += (int) $row->{$amountColumn};

                if ($bucket !== 'refunds') {
                    $days[$date]["{$bucket}_count"]++;
                }
            }
        };

        $add(Appointment::whereBetween('paid_at', [$from, $to])->get(['paid_at', 'total']), 'paid_at', 'total', 'grooming');
        $add(Package::whereBetween('paid_at', [$from, $to])->get(['paid_at', 'price']), 'paid_at', 'price', 'packages');
        $add(ProductOrder::whereBetween('paid_at', [$from, $to])->get(['paid_at', 'total']), 'paid_at', 'total', 'products');
        $add(Package::whereBetween('refunded_at', [$from, $to])->get(['refunded_at', 'refund_amount']), 'refunded_at', 'refund_amount', 'refunds');
        $add(ProductOrder::whereBetween('refunded_at', [$from, $to])->get(['refunded_at', 'total']), 'refunded_at', 'total', 'refunds');

        return collect($days)
            ->map(fn (array $day) => $day + ['total' => $day['grooming'] + $day['packages'] + $day['products'] - $day['refunds']])
            ->sortKeysDesc()
            ->values();
    }
}
