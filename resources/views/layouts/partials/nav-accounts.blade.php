@props(['panel'])

@php
    use Illuminate\Support\Facades\Route;

    // The expiring-accounts page has its own main-menu entry.
    $accountsOpen = request()->routeIs("{$panel}.accounts.*") && ! request()->routeIs("{$panel}.accounts.expiring*");
    $hasCategories = Route::has("{$panel}.accounts.wireguard")
        && Route::has("{$panel}.accounts.ppp")
        && Route::has("{$panel}.accounts.v2ray")
        && Route::has("{$panel}.accounts.anyconnect");

    $accountLinks = [
        ['route' => "{$panel}.accounts.wireguard", 'section' => 'accounts_wireguard', 'label' => __('menu.accounts_wireguard'), 'icon' => 'bx-shield-quarter'],
        ['route' => "{$panel}.accounts.ppp", 'section' => 'accounts_ppp', 'label' => __('menu.accounts_ppp'), 'icon' => 'bx-plug'],
        ['route' => "{$panel}.accounts.v2ray", 'section' => 'accounts_v2ray', 'label' => __('menu.accounts_v2ray'), 'icon' => 'bx-rocket'],
        ['route' => "{$panel}.accounts.anyconnect", 'section' => 'accounts_anyconnect', 'label' => __('menu.accounts_anyconnect'), 'icon' => 'bx-network-chart'],
    ];

    $accountLinks = array_values(array_filter(
        $accountLinks,
        fn (array $link): bool => Route::has($link['route'])
            && admin_section_allowed($link['section'])
    ));
@endphp

@if ($hasCategories && $accountLinks !== [] && admin_section_allowed('accounts'))
    <li class="vp-nav__group" aria-expanded="{{ $accountsOpen ? 'true' : 'false' }}">
        <button type="button" @class(['vp-nav__link', 'is-active' => $accountsOpen])>
            <i class="bx bx-group vp-nav__icon"></i>
            <span class="vp-nav__label">{{ __('menu.accounts') }}</span>
            <i class="bx bx-chevron-left vp-nav__arrow"></i>
        </button>
        <ul class="vp-nav__sub" style="{{ $accountsOpen ? 'display:flex' : 'display:none' }}">
            @foreach ($accountLinks as $link)
                <li>
                    <a href="{{ route($link['route']) }}" @class(['vp-nav__link', 'is-active' => request()->routeIs($link['route'])])>
                        <i class="bx {{ $link['icon'] }} vp-nav__icon"></i>
                        <span class="vp-nav__label">{{ $link['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </li>
@elseif (Route::has("{$panel}.accounts.index") && admin_section_allowed('accounts'))
    <li>
        <a href="{{ route("{$panel}.accounts.index") }}" @class(['vp-nav__link', 'is-active' => $accountsOpen])>
            <i class="bx bx-group vp-nav__icon"></i>
            <span class="vp-nav__label">{{ __('menu.accounts') }}</span>
        </a>
    </li>
@endif
