@php
    use Illuminate\Support\Facades\Route;

    $settingsLinks = array_values(array_filter([
        array('route' => 'admin.settings.index', 'section' => 'settings_general', 'label' => __('menu.settings_general'), 'icon' => 'bx-cog'),
        array('route' => 'admin.modules.index', 'section' => 'settings_modules', 'label' => __('menu.modules'), 'icon' => 'bx-extension'),
        array('route' => 'admin.gift-accounts.index', 'section' => 'settings_gift_accounts', 'label' => __('menu.gift_accounts'), 'icon' => 'bx-gift'),
        array('route' => 'admin.gifts.index', 'section' => 'settings_gifts', 'label' => __('menu.gifts'), 'icon' => 'bx-heart'),
        array('route' => 'admin.sms.index', 'section' => 'settings_sms', 'label' => __('menu.sms'), 'icon' => 'bx-message-dots'),
        array('route' => 'admin.kyc.settings', 'section' => 'settings_kyc', 'label' => __('menu.kyc'), 'icon' => 'bx-id-card'),
        array('route' => 'admin.kyc.index', 'section' => 'settings_kyc_documents', 'label' => __('menu.kyc_documents'), 'icon' => 'bx-folder-open'),
        module_active('payments')
            ? array('route' => 'admin.payment-gateways.index', 'section' => 'settings_payment_gateways', 'label' => __('payment_gateways.menu_label'), 'icon' => 'bx-credit-card-front')
            : null,
        module_active('payments')
            ? array('route' => 'admin.gateway-payments.index', 'section' => 'settings_gateway_payments', 'label' => __('payment_gateways.menu_history'), 'icon' => 'bx-history')
            : null,
        array('route' => 'admin.security.index', 'section' => 'settings_security', 'label' => __('security.hub_title'), 'icon' => 'bx-shield-quarter'),
        array('route' => 'admin.api-tokens.index', 'section' => 'settings_api_tokens', 'label' => __('api.admin_menu'), 'icon' => 'bx-plug'),
        array('route' => 'admin.servers.index', 'section' => 'settings_servers', 'label' => __('menu.servers'), 'icon' => 'bx-server'),
        module_active('migrate')
            ? array('route' => 'admin.migrate.index', 'section' => 'settings_migrate', 'label' => __('menu.migrate'), 'icon' => 'bx-transfer-alt')
            : null,
        // مدیریت مدیران پنل زیرمجموعهٔ تنظیمات است، ولی برخلاف بقیهٔ آیتم‌ها
        // با دسترسی بخش باز نمی‌شود: فقط مدیر اصلی آن را می‌بیند و فقط او هم
        // به مسیرش راه دارد (کلید super_only در config/admin_sections.php).
        is_super_admin()
            ? array('route' => 'admin.administrators.index', 'section' => 'settings', 'label' => __('admins.menu_label'), 'icon' => 'bx-shield-quarter')
            : null,
    ], fn ($link): bool => is_array($link)
        && Route::has($link['route'])
        && admin_section_allowed($link['section'])));

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

@if ($settingsLinks !== [] && admin_section_allowed('settings'))
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
