@php
    use App\Support\SmoothChart;

    $data = $this->chart();
    $values = array_column($data['months'], 'value');
    $chart = SmoothChart::make($values);
    $ticks = collect(range(0, 4))->map(fn ($step) => $chart['max'] * $step / 4);
@endphp

<x-filament-widgets::widget>
    <section class="bibi-band" aria-label="{{ $data['title'] }}">
        <div class="bibi-band-head">
            <div>
                <div class="bibi-band-title">{{ $data['title'] }}</div>
                <div class="bibi-band-sub">近 12 個月</div>
            </div>
            <nav class="bibi-band-actions" aria-label="快捷入口">
                @foreach ($this->quickActions() as $action)
                    <a href="{{ $action['url'] }}">{{ svg($action['icon']) }}{{ $action['label'] }}</a>
                @endforeach
            </nav>
        </div>

        <div class="bibi-band-plot">
            @foreach ($ticks as $tick)
                <div class="bibi-band-grid" style="top: {{ SmoothChart::yPercent($tick, $chart['max']) }}%">
                    <span>{{ number_format($tick) }}</span>
                </div>
            @endforeach

            <svg viewBox="0 0 1000 300" preserveAspectRatio="none" aria-hidden="true">
                <defs>
                    <linearGradient id="bibi-band-fill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0" stop-color="currentColor" stop-opacity=".22" />
                        <stop offset="1" stop-color="currentColor" stop-opacity="0" />
                    </linearGradient>
                </defs>
                <path d="{{ $chart['area'] }}" fill="url(#bibi-band-fill)" />
                <path d="{{ $chart['line'] }}" fill="none" stroke="currentColor" stroke-width="2.5" vector-effect="non-scaling-stroke" />
            </svg>

            @foreach ($chart['points'] as [$x, $y, $value])
                <span class="bibi-dot bibi-dot-band" style="left: {{ $x }}%; top: {{ $y }}%"
                    title="{{ $data['months'][$loop->index]['label'] }}：{{ $data['prefix'] }}{{ number_format($value) }}"></span>
            @endforeach

            <div class="bibi-band-months">
                @foreach ($chart['points'] as [$x])
                    <span style="left: {{ $x }}%">{{ $data['months'][$loop->index]['label'] }}</span>
                @endforeach
            </div>
        </div>
    </section>
</x-filament-widgets::widget>
