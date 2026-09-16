@php
    use Illuminate\Support\Facades\Route;

    $automationLinks = [
        ['route' => 'admin.automation.pricing', 'label' => __('menu.automation_pricing'), 'icon' => 'bx-purchase-tag'],
        ['route' => 'admin.automation.expiring', 'label' => __('ui.expiring_threshold_title'), 'icon' => 'bx-time-five'],
        ['route' => 'admin.automation.portal', 'label' => __('menu.automation_portal'), 'icon' => 'bx-mobile-alt'],
        ['route' => 'admin.automation.index', 'label' => __('menu.automation_cron'), 'icon' => 'bx-time-five'],
        ['route' => 'admin.settings.logs', 'label' => __('menu.settings_logs'), 'icon' => 'bx-file-find'],
        ['route' => 'admin.settings.server-backups.index', 'label' => __('menu.settings_server_backups'), 'icon' => 'bx-cloud-download'],
        ['route' => 'admin.broadcasts.index', 'label' => __('menu.broadcasts'), 'icon' => 'bx-broadcast'],
        ['route' => 'admin.client-payment-card.edit', 'label' => __('clients.payment_card_settings'), 'icon' => 'bx-credit-card'],
        ['route' => 'admin.maintenance.index', 'label' => __('menu.maintenance'), 'icon' => 'bx-data'],
    ];

    $automationLinks = array_values(array_filter(
        $automationLinks,
        fn (array $link): bool => Route::has($link['route'])
    ));

    $automationOpen = request()->routeIs('admin.automation.*')
        || request()->routeIs('admin.settings.logs')
        || request()->routeIs('admin.settings.logs.*')
        || request()->routeIs('admin.settings.server-backups.*')
        || request()->routeIs('admin.broadcasts.*')
        || request()->routeIs('admin.client-payment-card.*')
        || request()->routeIs('admin.payment-cards.*')
        || request()->routeIs('admin.maintenance.*');
@endphp

@if ($automationLinks !== [])
    <li class="vp-nav__group" aria-expanded="{{ $automationOpen ? 'true' : 'false' }}">
        <button type="button" @class(['vp-nav__link', 'is-active' => $automationOpen])>
            <i class="bx bx-bot vp-nav__icon"></i>
            <span class="vp-nav__label">{{ __('menu.automation') }}</span>
            <i class="bx bx-chevron-left vp-nav__arrow"></i>
        </button>
        <ul class="vp-nav__sub" style="{{ $automationOpen ? 'display:flex' : 'display:none' }}">
            @foreach ($automationLinks as $link)
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
