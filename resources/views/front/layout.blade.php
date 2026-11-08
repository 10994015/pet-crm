@php
    use App\Models\Setting;

    $shop = Setting::texts();
    $hours = Setting::current();
    $closed = Setting::closedWeekdays();
    $weekdays = ['日', '一', '二', '三', '四', '五', '六'];
    $hoursLabel = sprintf('%02d:%02d–%02d:%02d',
        intdiv((int) round($hours['booking_open_hour'] * 60), 60), (int) round($hours['booking_open_hour'] * 60) % 60,
        intdiv((int) round($hours['booking_close_hour'] * 60), 60), (int) round($hours['booking_close_hour'] * 60) % 60);
    $closedLabel = $closed ? '週'.collect($closed)->map(fn ($day) => $weekdays[$day])->implode('、').'公休' : '全年無休';
    $nav = [
        ['route' => 'front.home', 'label' => '首頁', 'icon' => 'home'],
        ['route' => 'front.booking', 'label' => '我要預約', 'icon' => 'calendar'],
        ['route' => 'front.my-booking', 'label' => '我的預約', 'icon' => 'check'],
        ['route' => 'front.member.home', 'label' => '會員中心', 'icon' => 'user', 'match' => 'front.member.*'],
    ];
    $member = auth('member')->user();
    $icons = [
        'home' => '<path d="M3 11.5 12 4l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M8 3v4m8-4v4M3.5 10h17M8 14h2m4 0h2m-8 3.5h2"/>',
        'check' => '<rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M8 3v4m8-4v4M3.5 10h17m-11.5 4.5 2 2 4-4"/>',
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'pin' => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'chat' => '<path d="M4 5.5h16v10H9l-5 4z"/>',
        'user' => '<circle cx="12" cy="8.5" r="4"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/>',
    ];
@endphp
<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '寵物美容預約') ｜ {{ config('app.name') }}</title>
    <meta name="description" content="{{ config('app.name') }} 寵物美容線上預約：選服務、挑時段，一分鐘完成。">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=LXGW+WenKai+TC:wght@400;700&family=Noto+Sans+TC:wght@400;500;700&family=Quicksand:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/front.css') }}?v={{ filemtime(public_path('css/front.css')) }}">
    <script defer src="{{ asset('js/instant-pick.js') }}?v={{ filemtime(public_path('js/instant-pick.js')) }}"></script>
    <script defer src="{{ asset('js/busy.js') }}?v={{ filemtime(public_path('js/busy.js')) }}"></script>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cg fill='%23e9a06b'%3E%3Cellipse cx='32' cy='42' rx='14' ry='12'/%3E%3Cellipse cx='14' cy='28' rx='6' ry='8'/%3E%3Cellipse cx='25' cy='16' rx='6' ry='8'/%3E%3Cellipse cx='39' cy='16' rx='6' ry='8'/%3E%3Cellipse cx='50' cy='28' rx='6' ry='8'/%3E%3C/g%3E%3C/svg%3E">
</head>
<body>
    <div class="shell">
        <aside class="side">
            <a class="brand" href="{{ route('front.home') }}">
                <x-front.art name="paw" class="brand-paw" />
                <span class="brand-name">{{ config('app.name') }}</span>
                <span class="brand-sub">Pet Grooming</span>
            </a>

            <nav class="side-nav" aria-label="主選單">
                @foreach ($nav as $item)
                    <a href="{{ route($item['route']) }}" @class(['is-active' => request()->routeIs($item['match'] ?? $item['route'])])>
                        <svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons[$item['icon']] !!}</svg>
                        {{ $item['label'] }}
                    </a>
                @endforeach
                <a href="{{ route('front.home') }}#contact">
                    <svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons['phone'] !!}</svg>
                    聯絡我們
                </a>
            </nav>

            <div class="side-art">
                <p>Happy Pets<br>Happy Life ♡</p>
                <x-front.art name="sitting" />
            </div>
        </aside>

        <div class="body">
            <header class="top">
                <a class="top-brand" href="{{ route('front.home') }}">
                    <x-front.art name="paw" class="brand-paw" />
                    {{ config('app.name') }}
                </a>
                <div class="top-info">
                    <span class="pill">
                        <svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons['clock'] !!}</svg>
                        營業 {{ $hoursLabel }} · {{ $closedLabel }}
                    </span>
                </div>
                <div class="top-actions">
                    @if ($shop['shop_phone'])
                        <a class="pill pill-link" href="tel:{{ preg_replace('/[^\d+]/', '', $shop['shop_phone']) }}">
                            <svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons['phone'] !!}</svg>
                            <span>{{ $shop['shop_phone'] }}</span>
                        </a>
                    @endif
                    <a class="pill pill-link pill-member" href="{{ route('front.member.home') }}">
                        <svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons['user'] !!}</svg>
                        <span>{{ $member ? $member->name : '會員登入' }}</span>
                    </a>
                    <a class="btn btn-sm" href="{{ route('front.booking') }}">立即預約</a>
                </div>
            </header>

            <main class="content">
                @yield('content')
            </main>

            <footer class="foot" id="contact">
                <div class="foot-card">
                    <div class="foot-brand">
                        <x-front.art name="paw" class="brand-paw" />
                        <div>
                            <strong>{{ config('app.name') }}</strong>
                            <span>用心照顧每一位毛孩</span>
                        </div>
                    </div>
                    <ul class="foot-list">
                        <li><svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons['clock'] !!}</svg>{{ $hoursLabel }}（{{ $closedLabel }}）</li>
                        @if ($shop['shop_phone'])
                            <li><svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons['phone'] !!}</svg><a href="tel:{{ preg_replace('/[^\d+]/', '', $shop['shop_phone']) }}">{{ $shop['shop_phone'] }}</a></li>
                        @endif
                        @if ($shop['shop_address'])
                            <li><svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons['pin'] !!}</svg><a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($shop['shop_address']) }}" target="_blank" rel="noopener">{{ $shop['shop_address'] }}</a></li>
                        @endif
                        @if ($shop['shop_line'])
                            <li><svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons['chat'] !!}</svg>LINE：{{ $shop['shop_line'] }}</li>
                        @endif
                    </ul>
                </div>
                <p class="foot-note">© {{ now()->year }} {{ config('app.name') }} · <a href="{{ route('front.privacy') }}">隱私權聲明</a> · <a href="{{ url('/admin') }}">店家登入</a></p>
            </footer>
        </div>
    </div>

    <nav class="tabbar" aria-label="主選單">
        @foreach ($nav as $item)
            <a href="{{ route($item['route']) }}" @class(['is-active' => request()->routeIs($item['match'] ?? $item['route'])])>
                <svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons[$item['icon']] !!}</svg>
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>
</body>
</html>
