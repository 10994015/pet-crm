<x-filament-panels::page>
    <style>
        .report-range { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; font-weight: 500; }
        .report-range input { border: 1px solid var(--gray-300); border-radius: .5rem; padding: .375rem .5rem; background: #fff; }
        .report-stats { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
        .report-stat { padding: 1rem; border-radius: .75rem; background: #fff; border: 1px solid var(--gray-200); }
        .report-stat-label { color: var(--gray-500); font-size: .875rem; }
        .report-stat-value { font-size: 1.5rem; font-weight: 600; font-variant-numeric: tabular-nums; }
        .report-tables { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
        .report-scroll { overflow-x: auto; }
        .report-table { width: 100%; font-size: .875rem; border-collapse: collapse; }
        .report-table th { text-align: start; color: var(--gray-500); font-weight: 500; padding: .375rem .5rem .375rem 0; white-space: nowrap; }
        .report-table td { padding: .5rem .5rem .5rem 0; border-top: 1px solid var(--gray-200); white-space: nowrap; }
        .report-table .num { text-align: end; font-variant-numeric: tabular-nums; }
        .report-empty { color: var(--gray-500); font-size: .875rem; }
    </style>

    <div class="report-range">
        <label for="report-from">日期</label>
        <input id="report-from" type="date" wire:model.live="from" aria-label="開始日期">
        <span>至</span>
        <input type="date" wire:model.live="to" aria-label="結束日期">
    </div>

    <div class="report-stats">
        @foreach ([
            ['實收合計', $revenue], ['美容', $grooming], ['包月方案', $packageSales],
            ['商品', $productSales], ['退款', $refunds], ['折扣總額', $discount], ['平均單價', $average],
        ] as [$label, $value])
            <div class="report-stat"><div class="report-stat-label">{{ $label }}</div><div class="report-stat-value">${{ number_format($value) }}</div></div>
        @endforeach
        <div class="report-stat"><div class="report-stat-label">收款筆數</div><div class="report-stat-value">{{ number_format($count) }}</div></div>
    </div>

    <x-filament::section heading="每日營業額">
        @if ($daily->isEmpty())
            <div class="report-empty">這段期間沒有資料</div>
        @else
            <div class="report-scroll">
                <table class="report-table">
                    <thead><tr><th>日期</th><th class="num">美容</th><th class="num">筆</th><th class="num">包月</th><th class="num">筆</th><th class="num">商品</th><th class="num">筆</th><th class="num">退款</th><th class="num">實收合計</th></tr></thead>
                    <tbody>
                        @foreach ($daily as $day)
                            <tr>
                                <td>{{ \Illuminate\Support\Carbon::parse($day['date'])->format('Y/m/d') }}</td>
                                <td class="num">${{ number_format($day['grooming']) }}</td><td class="num">{{ $day['grooming_count'] }}</td>
                                <td class="num">${{ number_format($day['packages']) }}</td><td class="num">{{ $day['packages_count'] }}</td>
                                <td class="num">${{ number_format($day['products']) }}</td><td class="num">{{ $day['products_count'] }}</td>
                                <td class="num">${{ number_format($day['refunds']) }}</td>
                                <td class="num"><strong>${{ number_format($day['total']) }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <div class="report-tables">
        @foreach ([
            ['付款方式', $byMethod, '實收'], ['美容師', $byGroomer, '實收'], ['單次與包月', $byVisitType, '實收'],
            ['主服務', $byService, '折扣前金額'], ['加購排行', $byAddOn, '折扣前金額'], ['寵物體型', $bySize, '實收'],
        ] as [$title, $rows, $amountLabel])
            <x-filament::section :heading="$title">
                @if ($rows->isEmpty())
                    <div class="report-empty">這段期間沒有資料</div>
                @else
                    <table class="report-table">
                        <thead><tr><th>項目</th><th class="num">筆數</th><th class="num">{{ $amountLabel }}</th></tr></thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr><td>{{ $row['name'] }}</td><td class="num">{{ number_format($row['count']) }}</td><td class="num">${{ number_format($row['total']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="今年累計">
        <div class="report-stats">
            <div><div class="report-stat-label">今年營收</div><div class="report-stat-value">${{ number_format($yearToDate) }}</div></div>
            <div><div class="report-stat-label">月平均</div><div class="report-stat-value">${{ number_format($monthlyAverage) }}</div></div>
            <div><div class="report-stat-label">較去年同期</div><div class="report-stat-value">{{ $yearGrowth === null ? '—' : ($yearGrowth > 0 ? '+' : '').$yearGrowth.'%' }}</div></div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
