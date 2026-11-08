<x-filament-widgets::widget>
    <section class="bibi-strip" aria-label="今日概況">
        @foreach ($this->stats() as $stat)
            <{{ $stat['url'] ? 'a' : 'div' }} @if ($stat['url']) href="{{ $stat['url'] }}" @endif class="bibi-strip-item bibi-tone-{{ $stat['color'] }}">
                {{ svg($stat['icon']) }}
                <span class="bibi-strip-value">{{ $stat['value'] }}</span>
                <span class="bibi-strip-label">{{ $stat['label'] }}</span>
            </{{ $stat['url'] ? 'a' : 'div' }}>
        @endforeach
    </section>
</x-filament-widgets::widget>
