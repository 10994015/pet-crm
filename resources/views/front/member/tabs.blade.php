{{-- The member area's own header: greeting and the three member pages. --}}
<section class="card member-head">
    <div class="member-hello">
        <div class="member-avatar tone-0"><x-front.art name="paw" /></div>
        <div>
            <p class="eyebrow">會員中心</p>
            <h1 class="hand">{{ $member->name }}，您好！</h1>
            <p class="muted small">
                會員編號 {{ str_pad((string) $member->getKey(), 5, '0', STR_PAD_LEFT) }}
                @if ($member->member_since) · {{ $member->member_since->format('Y/m/d') }} 加入 @endif
                @if ($member->isVip()) <span class="status status-vip">VIP</span> @endif
            </p>
        </div>
    </div>
    <nav class="member-tabs" aria-label="會員中心">
        <a href="{{ route('front.member.home') }}" @class(['is-active' => request()->routeIs('front.member.home')])>總覽</a>
        <a href="{{ route('front.member.history') }}" @class(['is-active' => request()->routeIs('front.member.history')])>美容紀錄</a>
        <a href="{{ route('front.member.profile') }}" @class(['is-active' => request()->routeIs('front.member.profile')])>我的資料</a>
        <form action="{{ route('front.member.logout') }}" method="post">
            @csrf
            <button type="submit">登出</button>
        </form>
    </nav>
</section>

@if (session('status'))
    <p class="flash flash-ok" role="status">{{ session('status') }}</p>
@endif
@if (session('error'))
    <p class="alert" role="alert">{{ session('error') }}</p>
@endif
