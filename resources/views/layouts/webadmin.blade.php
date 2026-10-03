<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ locale_dir() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title>@yield('title', config('app.name'))</title>
    <link rel="shortcut icon" href="{{ asset('images/shahpanel-logo.png') }}">
    @include('layouts.partials.webadmin-fonts')
    @include('layouts.partials.panel-vite', ['assets' => ['resources/css/app.css', 'resources/js/app.js']])
    @stack('styles')
</head>
<body @yield('body_attrs')>
@yield('body')
<script>
    // Any form with data-confirm asks before it is sent (approve, reject,
    // reassign...). Capture phase, so it runs before other submit handlers.
    document.addEventListener('submit', function (event) {
        var message = event.target && event.target.getAttribute && event.target.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
</script>
@stack('scripts')
</body>
</html>
