@php use App\Support\SmoothChart; @endphp

<x-filament-widgets::widget>
    <div class="bibi-trends">
        @foreach ($this->cards() as $card)
            @php($chart = SmoothChart::make(array_column($card['months'], 'value'), 1000, 300, 40, 6))
            <section class="bibi-trend" style="--tone: {{ $card['color'] }}">
                <div class="bibi-trend-head">
                    <span class="bibi-trend-label">{{ $card['label'] }}</span>
                    <span @class(['bibi-trend-change', 'is-down' => ! $card['trend']['up']]) title="和上個月同期相比">{{ $card['trend']['label'] }}</span>
                </div>
                <div class="bibi-trend-value">{{ $card['value'] }}</div>

                <div class="bibi-area">
                    <svg viewBox="0 0 1000 300" preserveAspectRatio="none" aria-hidden="true">
                        <defs>
                            <linearGradient id="bibi-fill-{{ $loop->index }}" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0" stop-color="{{ $card['color'] }}" stop-opacity=".4" />
                                <stop offset="1" stop-color="{{ $card['color'] }}" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        <path d="{{ $chart['area'] }}" fill="url(#bibi-fill-{{ $loop->index }})" />
                        <path d="{{ $chart['line'] }}" fill="none" stroke="{{ $card['color'] }}" stroke-width="2.5" vector-effect="non-scaling-stroke" />
                    </svg>
                    @foreach ($chart['points'] as [$x, $y, $value])
                        <span class="bibi-dot" style="left: {{ $x }}%; top: {{ $y }}%"
                            title="{{ $card['months'][$loop->index]['label'] }}：{{ ($card['format'])($value) }}"></span>
                    @endforeach
                </div>

                <div class="bibi-trend-foot">
                    <div class="bibi-trend-foot-title">{{ $card['footerTitle'] }}</div>
                    @forelse ($card['rows'] as [$name, $amount, $share])
                        <div class="bibi-trend-row">
                            <span>{{ $name }}</span>
                            <span>{{ $amount }}</span>
                            <span>{{ $share }}</span>
                        </div>
                    @empty
                        <div class="bibi-trend-row is-empty">這個月還沒有資料</div>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
</x-filament-widgets::widget>
