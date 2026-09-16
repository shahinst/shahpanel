<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ locale_dir() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">

    <title>@yield('title', config('app.name'))</title>

    <link href="/fonts/vazirmatn/vazirmatn.css" rel="stylesheet">
    <link href="/build/assets/app.css" rel="stylesheet">

    @stack('styles')
</head>
<body class="min-h-full bg-slate-50 text-slate-900 font-sans antialiased">
    <div id="app" class="min-h-full">
        @yield('content')
    </div>

    <script defer src="/build/assets/app.js"></script>
    @stack('scripts')
</body>
</html>
