@php
    $panel = 'admin';
    $mainLinks = [
        ['route' => 'admin.dashboard', 'section' => 'dashboard', 'label' => __('menu.dashboard'), 'icon' => 'bx-home-alt'],
        ['route' => 'admin.users.index', 'section' => 'agents', 'label' => __('menu.agents'), 'icon' => 'bx-user-pin'],
        module_active('dedicated') ? ['route' => 'admin.dedicated.index', 'section' => 'agents', 'label' => __('dedicated::admin.menu'), 'icon' => 'bx-server', 'also_active' => ['admin.dedicated.*']] : null,
        module_active('dedicated') ? ['route' => 'admin.inbound-allocations.index', 'section' => 'agents', 'label' => __('inbound_resellers.menu_admin'), 'icon' => 'bx-transfer-alt'] : null,
        ['route' => 'admin.sellers.index', 'section' => 'sellers', 'label' => __('menu.sellers'), 'icon' => 'bx-user'],
        ['route' => 'admin.clients.index', 'section' => 'clients', 'label' => __('menu.clients'), 'icon' => 'bx-group'],
        ['route' => 'admin.packages.index', 'section' => 'packages', 'label' => __('menu.packages'), 'icon' => 'bx-package', 'also_active' => ['admin.package-categories.*']],
        ['route' => 'admin.packages.pricing', 'section' => 'packages', 'label' => __('ui.menu_pricing'), 'icon' => 'bx-purchase-tag'],
        module_active('tunneling') ? ['route' => 'admin.tunneling.index', 'section' => 'tunneling', 'label' => __('menu.tunneling'), 'icon' => 'bx-git-branch', 'also_active' => ['admin.tunneling.*']] : null,
        module_active('shahbot') ? ['route' => 'admin.shahbot.index', 'section' => 'shahbot', 'label' => __('shahbot::admin.menu'), 'icon' => 'bxl-telegram', 'also_active' => ['admin.shahbot.*']] : null,
        // The tunnel changes the server's routing as root, so like its routes
        // (super_only in config/admin_sections.php) it is the main admin's alone.
        module_active('tgtunnel') && app(\App\Services\AdminSectionAccessService::class)->isSuperAdmin(auth()->user())
            ? ['route' => 'admin.tgtunnel.index', 'section' => 'dashboard', 'label' => __('tgtunnel::tunnel.title'), 'icon' => 'bx-shield-quarter', 'also_active' => ['admin.tgtunnel.*']] : null,
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

@include('layouts.partials.nav-expiring', ['panel' => $panel])

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

@include('layouts.partials.nav-extensions', ['panel' => 'admin'])
@include('layouts.partials.nav-admin-automation')

@include('layouts.partials.nav-admin-settings')
