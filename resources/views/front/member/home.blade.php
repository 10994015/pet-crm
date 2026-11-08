@extends('front.layout')

@section('title', '會員中心')

@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Storage;
@endphp

@section('content')
    <div class="page">
        <div class="page-main">
            @include('front.member.tabs')

            <section class="section">
                <div class="section-head">
                    <h2><span class="sparkle" aria-hidden="true">✦</span>即將到來的預約</h2>
                    <a class="link" href="{{ route('front.booking') }}">再約一次 ›</a>
                </div>
                @forelse ($upcoming as $visit)
                    @php([$label, $tone] = $visit->publicStatus())
                    <div class="card visit">
                        <div class="detail-art tone-{{ $loop->index % 4 }}"><x-front.art :name="$visit->pet->species?->value === 'cat' ? 'cat' : 'dog'" /></div>
                        <div class="visit-body">
                            <strong>{{ $visit->starts_at->locale('zh_TW')->isoFormat('M/D（dd）HH:mm') }}</strong>
                            <span>{{ $visit->pet->name }} · {{ $visit->items->pluck('name')->implode('、') ?: '美容' }}</span>
                        </div>
                        <div class="visit-side">
                            <span class="status status-{{ $tone }}">{{ $label }}</span>
                            @if ($visit->cancellableByOwner())
                                <form action="{{ route('front.member.cancel', $visit) }}" method="post"
                                    onsubmit="return confirm({{ Js::from("確定要取消 {$visit->pet->name} ".$visit->starts_at->format('n/j H:i').' 的預約嗎？') }})">
                                    @csrf
                                    <button class="text-btn" type="submit">取消預約</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="empty">
                        目前沒有預約。
                        <a class="link" href="{{ route('front.booking') }}">幫毛孩約一次 ›</a>
                    </div>
                @endforelse
                @if ($upcoming->isNotEmpty())
                    <p class="muted small visit-hint">
                        @if (($hours = (int) \App\Models\Setting::value('member_cancel_hours')) > 0)
                            預約前 {{ $hours }} 小時以前可以自己取消，之後要改時間或取消請直接來電。
                        @else
                            要改時間或取消，請直接來電。
                        @endif
                    </p>
                @endif
            </section>

            <section class="section">
                <div class="section-head">
                    <h2><span class="sparkle" aria-hidden="true">✦</span>我的毛孩</h2>
                </div>
                @if ($pets->isEmpty())
                    <div class="empty">還沒有毛孩的資料，第一次預約後就會出現在這裡。</div>
                @else
                    <div class="pet-grid">
                        @foreach ($pets as $pet)
                            <article class="card pet-card">
                                <div @class(['pet-photo', 'tone-'.($loop->index % 4)])>
                                    @if ($pet->photo)
                                        <img src="{{ Storage::disk('public')->url($pet->photo) }}" alt="{{ $pet->name }}">
                                    @else
                                        <x-front.art :name="$pet->species?->value === 'cat' ? 'cat' : 'dog'" />
                                    @endif
                                </div>
                                <div class="pet-info">
                                    <h3 class="hand">{{ $pet->name }}</h3>
                                    <p class="muted small">
                                        {{ collect([$pet->breed, $pet->size?->getLabel(), $pet->age_label])->filter()->implode(' · ') }}
                                    </p>
                                    <dl class="pet-facts">
                                        <div><dt>上次美容</dt><dd>{{ $pet->last_visit_at ? Carbon::parse($pet->last_visit_at)->format('Y/m/d') : '—' }}</dd></div>
                                        <div>
                                            <dt>建議下次</dt>
                                            <dd @class(['is-due' => $pet->next_visit_due_on?->lte(today()->addDays(7))])>
                                                {{ $pet->next_visit_due_on?->format('Y/m/d') ?? '—' }}
                                            </dd>
                                        </div>
                                    </dl>
                                    <a class="btn btn-sm btn-outline" href="{{ route('front.booking', ['pet' => $pet->getKey()]) }}">幫 {{ $pet->name }} 預約</a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>

        <aside class="page-aside">
            <section class="card member-stats">
                <div><strong>{{ $visits }}</strong><span>來店次數</span></div>
                <div><strong>{{ $pets->count() }}</strong><span>毛孩</span></div>
                <div><strong>{{ $upcoming->count() }}</strong><span>預約中</span></div>
            </section>

            <section class="card">
                <div class="card-head">
                    <h2>包月方案</h2>
                    <x-front.art name="paw" class="card-paw" />
                </div>
                @forelse ($packages as $package)
                    <div class="package">
                        <strong>{{ $package->contents() }}</strong>
                        @foreach ($package->pets as $pet)
                            @php($left = $package->remainingVisits($pet))
                            @php($total = $package->totalVisits($pet))
                            <div class="package-row">
                                <span>{{ $pet->name }}</span>
                                <span class="dots" aria-label="剩 {{ $left }} 次，共 {{ $total }} 次">
                                    @for ($i = 0; $i < $total; $i++)
                                        <i @class(['is-left' => $i < $left])></i>
                                    @endfor
                                </span>
                                <span class="package-left">剩 {{ $left }} 次</span>
                            </div>
                        @endforeach
                        <p class="muted small">使用期限到 {{ $package->expires_on->format('Y/m/d') }}</p>
                    </div>
                @empty
                    <p class="muted small">目前沒有使用中的包月方案。包月預付可享優惠，歡迎詢問店家。</p>
                @endforelse
            </section>

            <section class="card quick-cta">
                <x-front.art name="bath" class="cta-art" />
                <h2 class="hand">該洗香香囉？</h2>
                <p class="muted">會員預約不用再填資料。</p>
                <a class="btn btn-block" href="{{ route('front.booking') }}">立即預約 <span aria-hidden="true">›</span></a>
            </section>
        </aside>
    </div>
@endsection
