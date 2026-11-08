@extends('front.layout')

@section('title', '我的預約')

@section('content')
    <div class="page">
        <div class="page-main">
            @if ($appointment)
                @php([$label, $tone] = $appointment->publicStatus())
                <section class="card booking-detail">
                    <div class="detail-head">
                        <div class="detail-art tone-0"><x-front.art :name="$appointment->pet->species?->value === 'cat' ? 'cat' : 'dog'" /></div>
                        <div>
                            <p class="eyebrow">預約代碼 {{ $appointment->booking_code }}</p>
                            <h1 class="hand">{{ $appointment->pet->name }} 的美容預約</h1>
                            <span class="status status-{{ $tone }}">{{ $label }}</span>
                        </div>
                    </div>
                    <dl class="detail-list">
                        <div><dt>時間</dt><dd>{{ $appointment->starts_at->locale('zh_TW')->isoFormat('YYYY/M/D（dd）HH:mm') }}</dd></div>
                        <div><dt>服務</dt><dd>{{ $appointment->items->pluck('name')->implode('、') }}</dd></div>
                        <div><dt>預估金額</dt><dd>${{ number_format($appointment->total) }}<small>（以現場為準）</small></dd></div>
                    </dl>
                    <p class="muted">需要改時間或取消，請直接來電，我們會幫您處理。</p>
                    <div class="done-actions">
                        <a class="btn btn-outline" href="{{ route('front.my-booking') }}">查詢其他預約</a>
                        <a class="btn" href="{{ route('front.booking') }}">再約一次</a>
                    </div>
                </section>

                @if ($upcoming->isNotEmpty())
                    <section class="section">
                        <div class="section-head"><h2><span class="sparkle" aria-hidden="true">✦</span>其他即將到來的預約</h2></div>
                        <div class="visit-list">
                            @foreach ($upcoming as $visit)
                                @php([$label, $tone] = $visit->publicStatus())
                                <div class="card visit">
                                    <div class="detail-art tone-{{ $loop->index % 4 }}"><x-front.art :name="$visit->pet->species?->value === 'cat' ? 'cat' : 'dog'" /></div>
                                    <div class="visit-body">
                                        <strong>{{ $visit->starts_at->locale('zh_TW')->isoFormat('M/D（dd）HH:mm') }}</strong>
                                        <span>{{ $visit->pet->name }} · {{ $visit->items->pluck('name')->implode('、') }}</span>
                                    </div>
                                    <span class="status status-{{ $tone }}">{{ $label }}</span>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
            @else
                <section class="card lookup">
                    <x-front.art name="dog" class="lookup-art" />
                    <h1 class="hand">查詢我的預約</h1>
                    <p class="muted">輸入預約時填的手機號碼，和預約完成時拿到的 6 碼預約代碼。</p>
                    <form action="{{ route('front.my-booking.find') }}" method="post" class="stack lookup-form">
                        @csrf
                        <label class="field">
                            <span>手機號碼</span>
                            <input class="input" type="tel" name="phone" value="{{ old('phone') }}" placeholder="0912-345-678" autocomplete="tel" required>
                            @error('phone') <small class="field-error">{{ $message }}</small> @enderror
                        </label>
                        <label class="field">
                            <span>預約代碼</span>
                            <input class="input code-input" type="text" name="code" value="{{ old('code') }}" placeholder="例如 7KQ2MX" autocapitalize="characters" maxlength="12" required>
                            @error('code') <small class="field-error">{{ $message }}</small> @enderror
                        </label>
                        <button class="btn btn-block" type="submit">查看預約 <span aria-hidden="true">›</span></button>
                    </form>
                    <p class="muted small">忘記代碼了嗎？直接來電，我們幫您查。會員可以直接到 <a class="link" href="{{ route('front.member.home') }}">會員中心</a> 看所有預約。</p>
                </section>
            @endif
        </div>

        <aside class="page-aside">
            <section class="card quick-cta">
                <x-front.art name="bath" class="cta-art" />
                <h2 class="hand">想幫毛孩約下一次？</h2>
                <p class="muted">選服務、挑時段，一分鐘完成。</p>
                <a class="btn btn-block" href="{{ route('front.booking') }}">立即預約 <span aria-hidden="true">›</span></a>
            </section>
            <section class="card trust">
                <div><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 3 8.3 7 10 4-1.7 7-5.5 7-10V6z"/><path d="m9 12 2 2 4-4"/></svg><strong>安心專業</strong><span>細心溫柔</span></div>
                <div><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg><strong>用心照顧</strong><span>一對一服務</span></div>
                <div><x-front.art name="paw" /><strong>舒適環境</strong><span>寵物友善</span></div>
            </section>
        </aside>
    </div>
@endsection
