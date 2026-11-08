<x-filament-panels::page>
    <style>
        .schedule-bar { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .schedule-date { font-size: 1.125rem; font-weight: 600; margin-inline-end: auto; }
        .schedule-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); margin-top: 1rem; }
        .schedule-column { display: flex; flex-direction: column; gap: .5rem; }
        .schedule-groomer { font-weight: 600; display: flex; justify-content: space-between; }
        .schedule-count { color: var(--gray-500); font-weight: 400; font-size: .875rem; }
        .schedule-empty { color: var(--gray-500); font-size: .875rem; padding: .75rem 0; }
        .schedule-card { display: block; padding: .75rem; border-radius: .75rem; background: #fff; border: 1px solid var(--gray-200); color: inherit; text-decoration: none; }
        .dark .schedule-card { background: var(--gray-900); border-color: var(--gray-700); }
        a.schedule-card:hover { border-color: var(--primary-500); }
        .schedule-card-muted { opacity: .55; }
        .schedule-card-top { display: flex; justify-content: space-between; align-items: center; gap: .5rem; }
        .schedule-time { font-weight: 600; font-variant-numeric: tabular-nums; }
        .schedule-pet { font-weight: 600; margin-top: .375rem; }
        .schedule-line { color: var(--gray-500); font-size: .875rem; margin-top: .125rem; }
        .schedule-warning { color: var(--danger-600); font-size: .875rem; font-weight: 600; margin-top: .25rem; }
        .dark .schedule-warning { color: var(--danger-400); }
        .schedule-closed { margin-top: 1rem; padding: .75rem 1rem; border-radius: .75rem; background: var(--warning-50); color: var(--warning-700); font-weight: 600; }
    </style>

    <div class="schedule-bar">
        <div class="schedule-date">{{ $this->day()->locale('zh_TW')->isoFormat('YYYY/M/D（dddd）') }}</div>
        <x-filament::button color="gray" wire:click="shiftDays(-1)" icon="heroicon-m-chevron-left">前一天</x-filament::button>
        <x-filament::button color="gray" wire:click="goToToday">今天</x-filament::button>
        <x-filament::button color="gray" wire:click="shiftDays(1)" icon="heroicon-m-chevron-right" icon-position="after">後一天</x-filament::button>
        <input type="date" wire:model.live="date" aria-label="選擇日期" style="border: 1px solid var(--gray-300); border-radius: .5rem; padding: .375rem .5rem; background: transparent;">
        <x-filament::button tag="a" :href="$this->createUrl()" icon="heroicon-m-plus">新增預約</x-filament::button>
    </div>

    @if ($closure = $this->shopClosure())
        <div class="schedule-closed" role="status">這天全店店休{{ $closure->reason ? "（{$closure->reason}）" : '' }}，線上預約已關閉。</div>
    @endif

    <div class="schedule-grid">
        @foreach ($this->columns() as $column)
            <div class="schedule-column">
                <div class="schedule-groomer">
                    <span>{{ $column['name'] }}@if ($column['away']) <x-filament::badge color="warning" style="display: inline-flex; margin-inline-start: .25rem;">請假</x-filament::badge>@endif</span>
                    <span class="schedule-count">{{ $column['appointments']->count() }} 筆</span>
                </div>

                @forelse ($column['appointments'] as $appointment)
                    @php($url = $this->editUrl($appointment))
                    <{{ $url ? 'a' : 'div' }} @if ($url) href="{{ $url }}" @endif
                        @class(['schedule-card', 'schedule-card-muted' => ! $appointment->status->isActive()])>
                        <div class="schedule-card-top">
                            <span class="schedule-time">{{ $appointment->starts_at->format('H:i') }}–{{ $appointment->ends_at->format('H:i') }}</span>
                            <x-filament::badge :color="$appointment->status->getColor()">{{ $appointment->status->getLabel() }}</x-filament::badge>
                        </div>
                        <div class="schedule-pet">{{ $appointment->pet->name }}（{{ $appointment->pet->size->getLabel() }}）</div>
                        <div class="schedule-line">{{ $appointment->pet->customer->name }} {{ $appointment->pet->customer->phone }}</div>
                        <div class="schedule-line">{{ $appointment->items->pluck('name')->implode('、') }}</div>
                        @foreach ($appointment->pet->warnings() as $warning)
                            <div class="schedule-warning">⚠ {{ $warning }}</div>
                        @endforeach
                    </{{ $url ? 'a' : 'div' }}>
                @empty
                    <div class="schedule-empty">今天沒有預約</div>
                @endforelse
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
