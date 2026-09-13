@php
    use Illuminate\Support\Facades\Route;

    $settingsLinks = array_values(array_filter([
        array('route' => 'admin.settings.index', 'label' => __('menu.settings_general'), 'icon' => 'bx-cog'),
        array('route' => 'admin.modules.index', 'label' => __('menu.modules'), 'icon' => 'bx-extension'),
        array('route' => 'admin.gift-accounts.index', 'label' => __('menu.gift_accounts'), 'icon' => 'bx-gift'),
        array('route' => 'admin.gifts.index', 'label' => __('menu.gifts'), 'icon' => 'bx-heart'),
        array('route' => 'admin.sms.index', 'label' => __('menu.sms'), 'icon' => 'bx-message-dots'),
        array('route' => 'admin.kyc.settings', 'label' => __('menu.kyc'), 'icon' => 'bx-id-card'),
        array('route' => 'admin.kyc.index', 'label' => __('menu.kyc_documents'), 'icon' => 'bx-folder-open'),
        module_active('payments')
            ? array('route' => 'admin.payment-gateways.index', 'label' => __('payment_gateways.menu_label'), 'icon' => 'bx-credit-card-front')
            : null,
        module_active('payments')
            ? array('route' => 'admin.gateway-payments.index', 'label' => __('payment_gateways.menu_history'), 'icon' => 'bx-history')
            : null,
        array('route' => 'admin.security.index', 'label' => __('security.hub_title'), 'icon' => 'bx-shield-quarter'),
        array('route' => 'admin.api-tokens.index', 'label' => __('api.admin_menu'), 'icon' => 'bx-plug'),
        array('route' => 'admin.servers.index', 'label' => __('menu.servers'), 'icon' => 'bx-server'),
        module_active('migrate')
            ? array('route' => 'admin.migrate.index', 'label' => __('menu.migrate'), 'icon' => 'bx-transfer-alt')
            : null,
    ], fn ($link): bool => is_array($link) && Route::has($link['route'])));

    $settingsOpen = request()->routeIs('admin.settings.index')
        || request()->routeIs('admin.settings.update')
        || request()->routeIs('admin.modules.*')
        || request()->routeIs('admin.gift-accounts.*')
        || request()->routeIs('admin.gifts.*')
        || request()->routeIs('admin.sms.*')
        || request()->routeIs('admin.kyc.*')
        || request()->routeIs('admin.payment-gateways.*')
        || request()->routeIs('admin.gateway-payments.*')
        || request()->routeIs('admin.security.*')
        || request()->routeIs('admin.login-firewall.*')
        || request()->routeIs('admin.web-shield.*')
        || request()->routeIs('admin.api-tokens.*')
        || request()->routeIs('admin.servers.*')
        || request()->routeIs('admin.migrate.*');
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
                    $base = preg_replace('/\.(index|edit|create|show|update|approve|reject|pdf)$/', '', $link['route']);
                    $active = request()->routeIs($base.'.*') || request()->routeIs($link['route']);
                    if ($link['route'] === 'admin.security.index') {
                        $active = $active
                            || request()->routeIs('admin.login-firewall.*')
                            || request()->routeIs('admin.web-shield.*');
                    }
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
