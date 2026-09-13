@php
    $brandName = config('app.name');
    $panelLabel = match ($panel) {
        'admin' => __('roles.admin'),
        'agent' => __('roles.agent'),
        'seller' => __('roles.seller'),
        'client' => __('roles.client'),
        default => '',
    };
@endphp

<aside class="vp-sidebar" id="vp-sidebar">
    <a href="{{ route("{$panel}.dashboard") }}" class="vp-sidebar__brand">
        <span class="vp-sidebar__brand-logo"><i class="bx bx-shield-quarter"></i></span>
        <span class="vp-sidebar__brand-text">
            {{ $brandName }}
            <small>{{ $panelLabel }}</small>
        </span>
    </a>

    <div class="vp-sidebar__scroll vp-scroll">
        <ul class="vp-nav" id="side-menu">
            <li class="vp-nav__title">{{ __('menu.main_menu') }}</li>
            @include("layouts.partials.nav-{$panel}")
        </ul>
    </div>
</aside>
