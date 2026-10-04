@php
    $panel = 'agent';
    $links = [
        ['route' => 'agent.dashboard', 'label' => __('menu.dashboard'), 'icon' => 'bx-home-alt'],
        ['route' => 'agent.sellers.index', 'label' => __('menu.sellers'), 'icon' => 'bx-user'],
        ['route' => 'agent.clients.index', 'label' => __('menu.clients'), 'icon' => 'bx-group'],
    ];

    // Only agents the admin made inbound resellers see their inbounds page.
    if (\Illuminate\Support\Facades\Schema::hasTable('inbound_allocations')
        && \App\Models\InboundAllocation::query()->where('agent_user_id', auth()->id())->exists()) {
        $links[] = ['route' => 'agent.inbounds.index', 'label' => __('inbound_resellers.menu_agent'), 'icon' => 'bx-transfer-alt'];
    }
@endphp

@foreach ($links as $link)
    <x-sidebar-item
        :href="route($link['route'])"
        :label="$link['label']"
        :icon="$link['icon']"
        :active="request()->routeIs(str_replace('.index', '.*', str_replace('.edit', '.*', $link['route'])).'*') || request()->routeIs($link['route'])" />
@endforeach

@include('layouts.partials.nav-expiring', ['panel' => $panel])

@include('layouts.partials.nav-accounts', ['panel' => $panel])

@include('layouts.partials.nav-financial', ['panel' => $panel])

@include('layouts.partials.nav-support', ['panel' => $panel, 'showDepartments' => true])

@include('layouts.partials.nav-shahbot', ['panel' => $panel])

@include('layouts.partials.nav-extensions', ['panel' => $panel])
@include('layouts.partials.nav-panel-settings', ['panel' => $panel])
