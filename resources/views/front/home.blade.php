@extends('front.layout')

@section('title', '寵物美容線上預約')

@section('content')
    <div class="page">
        <div class="page-main">
            <section class="hero">
                <div class="hero-text">
                    <p class="eyebrow">{{ config('app.name') }} 寵物美容</p>
                    <h1 class="hand">讓毛孩更可愛<br>從一次美容開始</h1>
                    <svg class="hero-swash" viewBox="0 0 220 16" aria-hidden="true"><path d="M4 12C60 2 150 2 216 9" /></svg>
                    <p class="hero-lead">線上挑服務、選時段，一分鐘完成預約。<br class="wide-only">送出後店家會盡快確認。</p>
                    <a class="btn btn-lg" href="{{ route('front.booking') }}">立即預約 <span aria-hidden="true">›</span></a>
                </div>
                <div class="hero-art">
                    <span class="hero-bubble"></span>
                    <x-front.art name="dog" />
                    <svg class="hero-heart" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z" /></svg>
                </div>
            </section>

            <section class="section">
                <div class="section-head">
                    <h2><span class="sparkle" aria-hidden="true">✦</span>服務項目</h2>
                    <a class="link" href="{{ route('front.booking') }}">預約服務 <span aria-hidden="true">›</span></a>
                </div>

                @if ($services->isEmpty())
                    <p class="empty">服務項目整理中，歡迎直接來電預約。</p>
                @else
                    <div class="service-grid">
                        @foreach ($services as $service)
                            <a class="service-card" href="{{ route('front.booking', ['service' => $service->id]) }}">
                                <div @class(['service-media', 'tone-'.($loop->index % 4)])>
                                    @if ($service->photo)
                                        <img src="{{ Storage::disk('public')->url($service->photo) }}" alt="{{ $service->name }}" loading="lazy">
                                    @else
                                        <x-front.art :name="$service->artName()" />
                                    @endif
                                </div>
                                <div class="service-body">
                                    <div>
                                        <h3>{{ $service->name }}</h3>
                                        <p>{{ $service->description ?: ($service->category?->name ?? '專業美容') }}</p>
                                    </div>
                                    <span class="round-arrow" aria-hidden="true">›</span>
                                </div>
                                <div class="service-meta">
                                    <span>${{ number_format($service->price_small) }} 起</span>
                                    <span>約 {{ $service->duration_minutes ?: 60 }} 分鐘</span>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="promo">
                <div class="promo-text">
                    <h2 class="hand">包月方案更划算</h2>
                    <p>一次買 {{ (int) $package['package_visits'] }} 次，主服務享
                        <strong>{{ \App\Filament\Resources\Packages\PackageResource::rateLabel($package['package_discount']) }}</strong>，
                        {{ (int) $package['package_valid_months'] }} 個月內使用，到店直接扣次。</p>
                    <a class="btn btn-dark" href="#contact">到店詢問 <span aria-hidden="true">›</span></a>
                </div>
                <div class="promo-art">
                    <x-front.art name="cat" class="promo-cat" />
                    <x-front.art name="dog" class="promo-dog" />
                </div>
            </section>
        </div>

        <aside class="page-aside">
            <section class="card quick">
                <div class="card-head">
                    <h2><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M8 3v4m8-4v4M3.5 10h17"/></svg>快速預約</h2>
                    <x-front.art name="paw" class="card-paw" />
                </div>
                <ol class="steps">
                    <li class="is-current"><span>1</span>選擇服務</li>
                    <li><span>2</span>選擇時段</li>
                    <li><span>3</span>確認預約</li>
                </ol>
                <form action="{{ route('front.booking') }}" method="get" class="quick-form">
                    <label class="field-icon">
                        <x-front.art name="paw" />
                        <span class="sr-only">選擇服務項目</span>
                        <select name="service" required>
                            <option value="">選擇服務項目</option>
                            @foreach ($services as $service)
                                <option value="{{ $service->id }}">{{ $service->name }}（${{ number_format($service->price_small) }} 起）</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M8 3v4m8-4v4M3.5 10h17"/></svg>
                        <span class="sr-only">選擇日期</span>
                        <select name="date">
                            <option value="">選擇日期</option>
                            @foreach ($days as $day)
                                <option value="{{ $day->toDateString() }}">{{ $day->locale('zh_TW')->isoFormat('M/D（dd）') }}{{ $day->isToday() ? ' 今天' : '' }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="btn btn-block" type="submit">下一步 <span aria-hidden="true">›</span></button>
                </form>
            </section>

            <section class="card">
                <div class="card-head">
                    <h2><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M8 3v4m8-4v4M3.5 10h17m-11.5 4.5 2 2 4-4"/></svg>我的預約</h2>
                </div>
                <p class="muted">輸入預約時的手機號碼和預約代碼，就能查看預約狀態。</p>
                <form action="{{ route('front.my-booking.find') }}" method="post" class="stack">
                    @csrf
                    <input class="input" type="tel" name="phone" placeholder="手機號碼" autocomplete="tel" required>
                    <input class="input" type="text" name="code" placeholder="預約代碼，例如 7KQ2MX" autocapitalize="characters" required>
                    <button class="btn btn-outline btn-block" type="submit">查看預約詳情 <span aria-hidden="true">›</span></button>
                </form>
            </section>

            <section class="card trust">
                <div><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 3 8.3 7 10 4-1.7 7-5.5 7-10V6z"/><path d="m9 12 2 2 4-4"/></svg><strong>安心專業</strong><span>細心溫柔</span></div>
                <div><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg><strong>用心照顧</strong><span>一對一服務</span></div>
                <div><x-front.art name="paw" /><strong>舒適環境</strong><span>寵物友善</span></div>
            </section>
        </aside>
    </div>
@endsection
