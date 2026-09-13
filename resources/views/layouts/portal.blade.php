<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex, max-snippet:0, max-image-preview:none">
    <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="bingbot" content="noindex, nofollow, noarchive, nosnippet">
    <title>@yield('title', config('app.name'))</title>
    @include('layouts.partials.webadmin-fonts')
    @include('layouts.partials.panel-vite', ['assets' => ['resources/css/portal.css', 'resources/js/portal.js']])
    <style>
        .portal-category-head__icon-wrap,.portal-app-tile__icon-wrap{overflow:hidden;flex-shrink:0}
        .portal-category-head__icon-wrap{width:36px;height:36px}
        .portal-app-tile__icon-wrap{width:44px;height:44px}
        .portal-category-head__icon,.portal-app-tile__icon{max-width:100%;max-height:100%;width:100%;height:100%;object-fit:contain;display:block}
    </style>
    @stack('styles')
</head>
<body class="portal-body">
@yield('portal_content')
@stack('scripts')
</body>
</html>
