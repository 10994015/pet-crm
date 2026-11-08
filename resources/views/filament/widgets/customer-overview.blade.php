<x-filament-widgets::widget>
    <section class="bibi-customers">
        <div class="bibi-customers-head">
            <span class="bibi-card-title">{{ svg('heroicon-s-users') }}客戶總覽</span>
            <a class="bibi-more" href="{{ $this->indexUrl() }}">查看全部 ›</a>
        </div>

        @forelse ($this->customers() as $customer)
            @php
                $photo = $customer->pets->firstWhere('photo', '!=', null)?->photo;
                $lastVisit = \Illuminate\Support\Carbon::parse($customer->last_visit_at);
                $days = (int) $lastVisit->copy()->startOfDay()->diffInDays(today());
                $isVip = $customer->isVip();
            @endphp
            <a class="bibi-customer" href="{{ $this->customerUrl($customer) }}">
                <img class="bibi-avatar" alt=""
                    src="{{ $photo ? \Illuminate\Support\Facades\Storage::disk('public')->url($photo) : asset('images/brand/pet-placeholder.svg') }}">
                <span class="bibi-customer-text">
                    <span class="bibi-customer-name">{{ $customer->name }}</span>
                    <span class="bibi-customer-meta">
                        上次消費：{{ $lastVisit->format('m/d') }}（{{ $days > 0 ? "{$days} 天前" : ($days < 0 ? '已排定' : '今天') }}）
                    </span>
                </span>
                <span @class(['bibi-tier', 'bibi-tier-vip' => $isVip])>{{ $isVip ? 'VIP' : '一般會員' }}</span>
            </a>
        @empty
            <div class="bibi-empty">還沒有消費紀錄</div>
        @endforelse
    </section>
</x-filament-widgets::widget>
