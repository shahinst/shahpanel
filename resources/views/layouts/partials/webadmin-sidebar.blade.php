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
        {{-- Inline styles on purpose: public/build ships pre-compiled, so edits to resources/css would not reach a customer install until someone runs npm run build. --}}
        <span class="vp-sidebar__brand-logo" style="background:none;box-shadow:none;">
            <img src="{{ asset('images/shahpanel-logo.png') }}" alt="{{ $brandName }}"
                 style="width:100%;height:100%;object-fit:contain;border-radius:11px;">
        </span>
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
