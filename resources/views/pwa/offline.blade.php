<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ locale_dir() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('ui.offline_title') }} · {{ app_display_name() }}</title>
    <style>
        body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Vazirmatn,system-ui,sans-serif;background:#0f172a;color:#e2e8f0}
        .box{text-align:center;padding:2rem;max-width:26rem}
        .box img{width:72px;height:72px;margin-bottom:1rem}
        h1{font-size:1.25rem;margin:0 0 .5rem}
        p{margin:0 0 1.25rem;color:#94a3b8;line-height:1.7}
        button{border:0;border-radius:.6rem;padding:.6rem 1.3rem;background:#4f46e5;color:#fff;font:inherit;cursor:pointer}
    </style>
</head>
<body>
    <div class="box">
        <img src="{{ asset('images/pwa/icon-192.png') }}" alt="">
        <h1>{{ __('ui.offline_title') }}</h1>
        <p>{{ __('ui.offline_text') }}</p>
        <button type="button" onclick="location.reload()">{{ __('ui.offline_retry') }}</button>
    </div>
</body>
</html>
