<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Enums\PetSize;
use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Pet;
use App\Models\ProductOrder;
use App\Services\DashboardMetrics;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/** Three cards with this month's figure, its 12-month curve and a short breakdown. */
class TrendCards extends Widget
{
    protected string $view = 'filament.widgets.trend-cards';

    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    /** @return list<array<string, mixed>> */
    public function cards(): array
    {
        return [
            $this->card('本月預約數', DashboardMetrics::bookings(...), '#f96332', fn ($v) => number_format($v), '本月熱門服務', $this->topServices()),
            auth()->user()->isOwner()
                ? $this->card('本月營收', DashboardMetrics::revenue(...), '#18ce0f', fn ($v) => '$'.number_format($v), '本月付款方式', $this->paymentMethods())
                : $this->card('本月新客戶', DashboardMetrics::newCustomers(...), '#18ce0f', fn ($v) => number_format($v), '最近的新客戶', $this->latestCustomers()),
            $this->card('會員數', DashboardMetrics::customers(...), '#2ca8ff', fn ($v) => number_format($v), '寵物體型', $this->petSizes(), cumulative: true),
        ];
    }

    /** @param  list<array{0: string, 1: string, 2: ?string}>  $rows */
    private function card(string $label, callable $measure, string $color, callable $format, string $footerTitle, array $rows, bool $cumulative = false): array
    {
        $months = DashboardMetrics::monthly($measure(...));

        return [
            'label' => $label,
            'value' => $format(end($months)['value']),
            'trend' => DashboardMetrics::trend($measure(...), $cumulative),
            'color' => $color,
            'months' => $months,
            'format' => $format,
            'footerTitle' => $footerTitle,
            'rows' => $rows,
        ];
    }

    private function share(int|float $part, int|float $whole): string
    {
        return $whole > 0 ? round($part / $whole * 100).'%' : '—';
    }

    /** @return list<array{0: string, 1: string, 2: ?string}> */
    private function topServices(): array
    {
        $items = AppointmentItem::query()
            ->whereHas('appointment', fn ($query) => $query
                ->whereBetween('starts_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->where('status', '!=', AppointmentStatus::Cancelled))
            ->pluck('name');

        return $items->countBy()->sortDesc()->take(3)
            ->map(fn (int $count, string $name) => [$name, "{$count} 次", $this->share($count, $items->count())])
            ->values()
            ->all();
    }

    /** @return list<array{0: string, 1: string, 2: ?string}> */
    private function paymentMethods(): array
    {
        $range = [now()->startOfMonth(), now()->endOfMonth()];
        $rows = collect()
            ->concat(Appointment::whereBetween('paid_at', $range)->get(['payment_method', 'total'])->map(fn ($a) => [$a->payment_method, $a->total]))
            ->concat(Package::whereBetween('paid_at', $range)->get(['payment_method', 'price'])->map(fn ($p) => [$p->payment_method, $p->price]))
            ->concat(ProductOrder::whereBetween('paid_at', $range)->get(['payment_method', 'total'])->map(fn ($o) => [$o->payment_method, $o->total]))
            ->filter(fn (array $row) => $row[1] > 0);

        $total = $rows->sum(1);

        return $rows->groupBy(fn (array $row) => $row[0]?->getLabel() ?? '未記錄')
            ->map(fn (Collection $group) => $group->sum(1))
            ->sortDesc()
            ->take(3)
            ->map(fn (int $amount, string $method) => [$method, '$'.number_format($amount), $this->share($amount, $total)])
            ->values()
            ->all();
    }

    /** @return list<array{0: string, 1: string, 2: ?string}> */
    private function latestCustomers(): array
    {
        return Customer::latest()->limit(3)->get()
            ->map(fn (Customer $customer) => [$customer->name, $customer->created_at->format('m/d'), null])
            ->all();
    }

    /** @return list<array{0: string, 1: string, 2: ?string}> */
    private function petSizes(): array
    {
        $counts = Pet::where('is_active', true)->pluck('size')->countBy(fn (PetSize $size) => $size->getLabel());
        $total = $counts->sum();

        return collect(PetSize::cases())
            ->map(fn (PetSize $size) => [$size->getLabel(), ($counts[$size->getLabel()] ?? 0).' 隻', $this->share($counts[$size->getLabel()] ?? 0, $total)])
            ->all();
    }
}
