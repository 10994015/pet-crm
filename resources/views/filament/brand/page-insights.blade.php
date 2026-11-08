@php($chips = \App\Filament\Support\PageInsights::for($scopes))
@if ($chips)
    <ul class="bibi-insights" aria-label="重點數字">
        @foreach ($chips as $chip)
            <li @class(['bibi-insight', 'is-warn' => ($chip['tone'] ?? null) === 'warn'])>
                {{ svg($chip['icon']) }}
                <span class="bibi-insight-label">{{ $chip['label'] }}</span>
                <span class="bibi-insight-value">{{ $chip['value'] }}</span>
            </li>
        @endforeach
    </ul>
@endif
