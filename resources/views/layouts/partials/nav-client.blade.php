@php
    $panel = 'client';
    $links = [
        ['route' => 'client.dashboard', 'label' => __('menu.dashboard'), 'icon' => 'bx-home-alt'],
        ['route' => 'client.shop.index', 'label' => __('clients.buy_account'), 'icon' => 'bx-cart'],
        ['route' => 'client.accounts.index', 'label' => __('clients.my_accounts'), 'icon' => 'bx-server'],
        ['route' => 'client.payment-requests.index', 'label' => __('menu.payment_requests'), 'icon' => 'bx-money'],
    ];
@endphp

@foreach ($links as $link)
    <x-sidebar-item
        :href="route($link['route'])"
        :label="$link['label']"
        :icon="$link['icon']"
        :active="request()->routeIs(str_replace('.index', '.*', $link['route']).'*') || request()->routeIs($link['route'])" />
@endforeach
