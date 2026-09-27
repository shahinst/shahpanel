@php
    $panel = 'admin';
    $mainLinks = [
        ['route' => 'admin.dashboard', 'section' => 'dashboard', 'label' => __('menu.dashboard'), 'icon' => 'bx-home-alt'],
        ['route' => 'admin.users.index', 'section' => 'agents', 'label' => __('menu.agents'), 'icon' => 'bx-user-pin'],
        ['route' => 'admin.sellers.index', 'section' => 'sellers', 'label' => __('menu.sellers'), 'icon' => 'bx-user'],
        ['route' => 'admin.clients.index', 'section' => 'clients', 'label' => __('menu.clients'), 'icon' => 'bx-group'],
        ['route' => 'admin.packages.index', 'section' => 'packages', 'label' => __('menu.packages'), 'icon' => 'bx-package', 'also_active' => ['admin.package-categories.*']],
        ['route' => 'admin.packages.pricing', 'section' => 'packages', 'label' => __('ui.menu_pricing'), 'icon' => 'bx-purchase-tag'],
        module_active('tunneling') ? ['route' => 'admin.tunneling.index', 'section' => 'tunneling', 'label' => __('menu.tunneling'), 'icon' => 'bx-git-branch', 'also_active' => ['admin.tunneling.*']] : null,
    ];
    $bottomLinks = [
        ['route' => 'admin.reports.index', 'section' => 'reports', 'label' => __('menu.reports'), 'icon' => 'bx-bar-chart-alt-2'],
    ];

    // A module-gated entry is null while its module is off, so the filter has to
    // accept null the way nav-admin-settings already does — typing it as array
    // makes the whole admin menu throw as soon as a module is deactivated.
    $mainLinks = array_values(array_filter(
        $mainLinks,
        fn ($link): bool => is_array($link)
            && \Illuminate\Support\Facades\Route::has($link['route'])
            && admin_section_allowed($link['section'])
    ));
    $bottomLinks = array_values(array_filter(
        $bottomLinks,
        fn ($link): bool => is_array($link)
            && \Illuminate\Support\Facades\Route::has($link['route'])
            && admin_section_allowed($link['section'])
    ));
@endphp

@foreach ($mainLinks as $link)
    @php
        $isActive = request()->routeIs(str_replace('.index', '.*', $link['route']).'*')
            || request()->routeIs($link['route']);
        foreach ($link['also_active'] ?? [] as $pattern) {
            if (request()->routeIs($pattern)) {
                $isActive = true;
                break;
            }
        }
    @endphp
    <x-sidebar-item
        :href="route($link['route'])"
        :label="$link['label']"
        :icon="$link['icon']"
        :active="$isActive" />
@endforeach

{{-- مدیریت مدیران پنل: فقط مدیر اصلی این آیتم را می‌بیند و فقط او هم به
    مسیرش راه دارد (کلید super_only در config/admin_sections.php). --}}
@if (is_super_admin())
    <x-sidebar-item
        :href="route('admin.administrators.index')"
        :label="__('admins.menu_label')"
        icon="bx-shield-quarter"
        :active="request()->routeIs('admin.administrators.*')" />
@endif

@include('layouts.partials.nav-accounts', ['panel' => $panel])

@include('layouts.partials.nav-financial', ['panel' => $panel])

@include('layouts.partials.nav-support', ['panel' => $panel, 'showDepartments' => true])

@foreach ($bottomLinks as $link)
    <x-sidebar-item
        :href="route($link['route'])"
        :label="$link['label']"
        :icon="$link['icon']"
        :active="request()->routeIs(str_replace('.index', '.*', $link['route']).'*') || request()->routeIs($link['route'])" />
@endforeach

@include('layouts.partials.nav-admin-automation')

@include('layouts.partials.nav-admin-settings')
