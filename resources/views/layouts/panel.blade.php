@extends('layouts.webadmin')

@php
    $routeName = request()->route()?->getName() ?? '';
    $routePanel = explode('.', $routeName)[0] ?: 'admin';
    $panel = in_array($routePanel, ['admin', 'agent', 'seller', 'client'], true)
        ? $routePanel
        : match (auth()->user()?->role) {
            \App\Enums\UserRole::Admin => 'admin',
            \App\Enums\UserRole::Agent => 'agent',
            \App\Enums\UserRole::Seller => 'seller',
            \App\Enums\UserRole::Client => 'client',
            default => 'admin',
        };
@endphp

@section('body_attrs') class="vp-panel-app panel-{{ $panel }}" @endsection

@section('body')
<div id="layout-wrapper">
    <div class="vp-sidebar-overlay" id="vp-sidebar-overlay" aria-hidden="true"></div>
    @include('layouts.partials.webadmin-sidebar')
    @include('layouts.partials.webadmin-topbar')

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                @if (session('success'))
                    <x-alert type="success">{{ session('success') }}</x-alert>
                @endif
                @if (session('warning'))
                    <x-alert type="warning">{{ session('warning') }}</x-alert>
                @endif
                @if (session('error'))
                    <x-alert type="error">{{ session('error') }}</x-alert>
                @endif
                @if ($errors->any())
                    <x-alert type="error">
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif

                @include('layouts.partials.impersonation-banner')
                @include('layouts.partials.update-banner')

                @yield('panel_content')
            </div>
        </div>

        <footer class="footer">
            @if ($panel !== 'client')
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span>{{ jalali_now('Y') }} © {{ config('app.name') }}</span>
                    @include('layouts.partials.author-links', ['variant' => 'footer'])
                </div>
            @else
                {{ jalali_now('Y') }} © {{ config('app.name') }}
            @endif
        </footer>
    </div>
</div>
@endsection
