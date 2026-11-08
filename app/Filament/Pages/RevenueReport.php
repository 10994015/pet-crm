<?php

namespace App\Filament\Pages;

use App\Enums\PetSize;
use App\Enums\ServiceType;
use App\Filament\Support\ExcelExport;
use App\Models\Appointment;
use App\Models\Package;
use App\Models\ProductOrder;
use App\Models\Service;
use App\Services\Revenue;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Throwable;

class RevenueReport extends Page
{
    protected string $view = 'filament.pages.revenue-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ChartBar;

    protected static ?string $navigationLabel = '營收報表';

    protected static ?string $title = '營收報表';

    protected static ?int $navigationSort = 60;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public function mount(): void
    {
        $this->from = $this->start()->toDateString();
        $this->to = $this->end()->toDateString();
    }

    public function start(): Carbon
    {
        return $this->parse($this->from, today()->startOfMonth())->startOfDay();
    }

    public function end(): Carbon
    {
        return $this->parse($this->to, today())->endOfDay();
    }

    private function parse(string $value, Carbon $fallback): Carbon
    {
        try {
            return filled($value) ? Carbon::createFromFormat('Y-m-d', $value) : $fallback;
        } catch (Throwable) {
            return $fallback;
        }
    }

    /** @return Collection<int, Appointment> */
    private function appointments(): Collection
    {
        return Appointment::with(['pet.customer', 'items', 'groomer'])
            ->whereBetween('paid_at', [$this->start(), $this->end()])
            ->orderBy('paid_at')
            ->get();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $appointments = $this->appointments();
        $packages = Package::whereBetween('paid_at', [$this->start(), $this->end()])->get();
        $products = ProductOrder::whereBetween('paid_at', [$this->start(), $this->end()])->get();
        $daily = Revenue::daily($this->start(), $this->end());
        $sales = $appointments->count() + $packages->count() + $products->count();
        $revenue = $daily->sum('total');

        return [
            'revenue' => $revenue,
            'grooming' => $daily->sum('grooming'),
            'packageSales' => $daily->sum('packages'),
            'productSales' => $daily->sum('products'),
            'refunds' => $daily->sum('refunds'),
            'discount' => $appointments->sum('discount') + $products->sum('discount'),
            'count' => $sales,
            'average' => $sales ? (int) round(($revenue + $daily->sum('refunds')) / $sales) : 0,
            'daily' => $daily,
            ...$this->trends(),
            'byMethod' => $this->byPaymentMethod($appointments, $packages, $products),
            'byGroomer' => $this->totals($appointments, fn (Appointment $a) => $a->groomer?->name ?? '未指定'),
            'byService' => $this->byService($appointments, ServiceType::Standard),
            'byAddOn' => $this->byService($appointments, ServiceType::Optional),
            'bySize' => $this->totals($appointments, fn (Appointment $a) => $a->pet->size?->getLabel() ?? PetSize::Small->getLabel()),
            'byVisitType' => $this->totals($appointments, fn (Appointment $a) => $a->package_id ? '包月到店' : '單次消費'),
        ];
    }

    /** Longer-range figures for judging how the shop is doing overall. */
    private function trends(): array
    {
        $thisYear = Revenue::total(today()->startOfYear(), today()->endOfDay());
        $lastYear = Revenue::total(today()->subYearNoOverflow()->startOfYear(), today()->subYearNoOverflow()->endOfDay());

        return [
            'yearToDate' => $thisYear,
            'monthlyAverage' => (int) round($thisYear / today()->month),
            'yearGrowth' => $lastYear > 0 ? (int) round(($thisYear - $lastYear) / $lastYear * 100) : null,
        ];
    }

    private function byPaymentMethod(Collection $appointments, Collection $packages, Collection $products): Collection
    {
        $rows = $appointments->map(fn ($a) => [$a->payment_method, $a->total])
            ->concat($packages->map(fn ($p) => [$p->payment_method, $p->price]))
            ->concat($products->map(fn ($o) => [$o->payment_method, $o->total]))
            ->filter(fn (array $row) => $row[1] > 0);

        return $rows
            ->groupBy(fn (array $row) => $row[0]?->getLabel() ?? '未記錄')
            ->map(fn (Collection $group, string $name) => [
                'name' => $name,
                'count' => $group->count(),
                'total' => $group->sum(1),
            ])
            ->sortByDesc('total')
            ->values();
    }

    private function totals(Collection $appointments, callable $groupBy): Collection
    {
        return $appointments
            ->groupBy($groupBy)
            ->map(fn (Collection $group, string $name) => [
                'name' => $name,
                'count' => $group->count(),
                'total' => $group->sum('total'),
            ])
            ->sortByDesc('total')
            ->values();
    }

    private function byService(Collection $appointments, ServiceType $type): Collection
    {
        $ids = Service::where('type', $type)->pluck('id')->flip();

        return $appointments
            ->flatMap->items
            ->filter(fn ($item) => $ids->has($item->service_id))
            ->groupBy('name')
            ->map(fn (Collection $items, string $name) => [
                'name' => $name,
                'count' => $items->count(),
                'total' => $items->sum('price'),
            ])
            ->sortByDesc('count')
            ->values();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('匯出 Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => ExcelExport::download('營收報表', [
                    '每日統計' => Revenue::daily($this->start(), $this->end())->map(fn (array $day) => [
                        '日期' => $day['date'],
                        '美容筆數' => $day['grooming_count'],
                        '美容金額' => $day['grooming'],
                        '包月筆數' => $day['packages_count'],
                        '包月金額' => $day['packages'],
                        '商品筆數' => $day['products_count'],
                        '商品金額' => $day['products'],
                        '退款' => $day['refunds'],
                        '實收合計' => $day['total'],
                    ]),
                    '美容明細' => $this->appointments()->map(fn (Appointment $appointment) => [
                        '結帳時間' => $appointment->paid_at->format('Y/m/d H:i'),
                        '飼主' => $appointment->pet->customer->name,
                        '寵物' => $appointment->pet->name,
                        '服務' => $appointment->items->pluck('name')->implode('、'),
                        '類型' => $appointment->package_id ? '包月到店' : '單次消費',
                        '美容師' => $appointment->groomer?->name ?? '未指定',
                        '小計' => $appointment->subtotal,
                        '折扣' => $appointment->discount,
                        '實收' => $appointment->total,
                        '付款方式' => $appointment->payment_method?->getLabel(),
                    ]),
                ])),
        ];
    }
}
