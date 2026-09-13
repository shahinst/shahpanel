<!doctype html>
<html lang="fa" dir="rtl">
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
@stack('scripts')
</body>
</html>
