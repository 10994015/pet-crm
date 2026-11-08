@php
    use App\Enums\ServiceType;

    $livewire = $getLivewire();
    $selected = collect($livewire->data['items'] ?? [])->pluck('service_id')->map(fn ($id) => (int) $id)->all();
    $pet = \App\Models\Pet::find($livewire->data['pet_id'] ?? null);
    $packageServiceId = \App\Models\Package::find($livewire->data['package_id'] ?? null)?->service_id;
    $groups = \App\Models\Service::query()
        ->with('category')
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->get()
        ->sortBy(fn ($service) => $service->category?->sort_order ?? PHP_INT_MAX)
        ->groupBy(fn ($service) => $service->category?->name ?? '其他');
@endphp

<div class="bibi-picker">
    @if ($pet)
        <button type="button" class="bibi-picker-copy" wire:click="copyLastServices">帶入上次的服務</button>
    @endif

    @foreach ($groups as $category => $services)
        <div class="bibi-picker-group">
            <div class="bibi-picker-title">{{ $category }}</div>
            <div class="bibi-picker-grid" data-pick-group>
                @foreach ($services as $service)
                    @php($isOn = in_array($service->getKey(), $selected, true))
                    <button type="button" wire:key="service-tile-{{ $service->getKey() }}"
                        wire:click="toggleService({{ $service->getKey() }})"
                        data-pick="{{ $service->type === ServiceType::Standard ? 'switch' : 'toggle' }}" data-pick-class="bibi-tile-on"
                        aria-pressed="{{ $isOn ? 'true' : 'false' }}"
                        @class(['bibi-tile', 'bibi-tile-on' => $isOn])>
                        <span class="bibi-tile-name">{{ $service->name }}</span>
                        <span class="bibi-tile-meta">
                            @if ($service->getKey() === $packageServiceId)
                                包月已付
                            @else
                                ${{ number_format($pet ? $pet->priceFor($service) : $service->priceFor(null)) }}
                            @endif
                            @if ($service->type === ServiceType::Optional)
                                · 加購
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
