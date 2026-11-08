{{-- Stands alone on purpose: this has to render even when the database, session or website layout are the thing that broke. --}}
<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') ｜ {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; padding: 24px 16px;
            display: grid; place-items: center;
            background: #faf4ed; color: #4a382d;
            font: 15px/1.7 'Noto Sans TC', 'Microsoft JhengHei', 'PingFang TC', system-ui, sans-serif;
        }
        main {
            width: min(460px, 100%); padding: 40px 28px;
            text-align: center;
            background: #fffdfa; border-radius: 22px;
            box-shadow: 0 10px 30px -18px rgba(120, 80, 50, .35);
        }
        svg { width: 56px; height: 56px; fill: #d98a55; }
        .code { margin: 12px 0 0; font-size: 13px; letter-spacing: .08em; color: #8b7565; }
        h1 { margin: 4px 0 8px; font-size: 26px; }
        p { margin: 0; }
        a {
            display: inline-block; margin-top: 22px; padding: 11px 26px;
            border-radius: 999px; background: #4a382d; color: #fff;
            font-weight: 700; text-decoration: none;
        }
        a:hover { background: #65503f; }
    </style>
</head>
<body>
    <main>
        <svg viewBox="0 0 64 64" aria-hidden="true">
            <ellipse cx="32" cy="42" rx="14" ry="12"/><ellipse cx="14" cy="28" rx="6" ry="8"/><ellipse cx="25" cy="16" rx="6" ry="8"/><ellipse cx="39" cy="16" rx="6" ry="8"/><ellipse cx="50" cy="28" rx="6" ry="8"/>
        </svg>
        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a href="{{ url('/') }}">回到首頁</a>
    </main>
</body>
</html>
