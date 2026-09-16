<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ locale_dir() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title>@yield('title', app_display_name())</title>
    <link rel="preload" href="{{ asset('fonts/vazirmatn/Vazirmatn-Regular.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/vazirmatn/Vazirmatn-Bold.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link href="{{ asset('fonts/vazirmatn/vazirmatn.css') }}" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/login.css') }}">
    @include('layouts.partials.auth-critical-css')
    @stack('styles')
</head>
<body>
<div class="authentication-bg min-vh-100">
    <div class="container auth-page-container">
        <div class="d-flex flex-column min-vh-100 px-3 pt-4">
            <div class="row justify-content-center my-auto">
                <div class="col-md-8 col-lg-6 col-xl-5">
                    @yield('content')
                </div>
            </div>
        </div>
    </div>
</div>
@stack('scripts')
</body>
</html>
