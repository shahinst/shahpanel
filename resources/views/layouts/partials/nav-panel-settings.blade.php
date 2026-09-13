@props(['panel'])

@php
    use Illuminate\Support\Facades\Route;

    $settingsLinks = array_values(array_filter([
        Route::has("{$panel}.client-payment-card.edit")
            ? ['route' => "{$panel}.client-payment-card.edit", 'label' => __('clients.payment_card_settings'), 'icon' => 'bx-credit-card']
            : null,
        Route::has("{$panel}.storefront.edit")
            ? ['route' => "{$panel}.storefront.edit", 'label' => __('menu.storefront'), 'icon' => 'bx-store']
            : null,
        Route::has("{$panel}.broadcasts.index")
            ? ['route' => "{$panel}.broadcasts.index", 'label' => __('menu.broadcasts'), 'icon' => 'bx-broadcast']
            : null,
        Route::has("{$panel}.api-tokens.index")
            ? ['route' => "{$panel}.api-tokens.index", 'label' => __('api.menu_title'), 'icon' => 'bx-plug']
            : null,
    ]));

    $settingsOpen = request()->routeIs("{$panel}.client-payment-card.*")
        || request()->routeIs("{$panel}.payment-cards.*")
        || request()->routeIs("{$panel}.storefront.*")
        || request()->routeIs("{$panel}.broadcasts.*")
        || request()->routeIs("{$panel}.api-tokens.*");
@endphp

@if ($settingsLinks !== [])
    <li class="vp-nav__group" aria-expanded="{{ $settingsOpen ? 'true' : 'false' }}">
        <button type="button" @class(['vp-nav__link', 'is-active' => $settingsOpen])>
            <i class="bx bx-cog vp-nav__icon"></i>
            <span class="vp-nav__label">{{ __('menu.settings') }}</span>
            <i class="bx bx-chevron-left vp-nav__arrow"></i>
        </button>
        <ul class="vp-nav__sub" style="{{ $settingsOpen ? 'display:flex' : 'display:none' }}">
            @foreach ($settingsLinks as $link)
                @php
                    $base = preg_replace('/\.(index|edit|create|show|update)$/', '', $link['route']);
                    $active = request()->routeIs($base.'.*') || request()->routeIs($link['route']);
                @endphp
                <li>
                    <a href="{{ route($link['route']) }}" @class(['vp-nav__link', 'is-active' => $active])>
                        <i class="bx {{ $link['icon'] }} vp-nav__icon"></i>
                        <span class="vp-nav__label">{{ $link['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </li>
@endif
