@props(['panel'])

@php
    use App\Services\AgentMarginCorrectionNoticeService;
    use Illuminate\Support\Facades\Route;

    $correctionNotice = app(AgentMarginCorrectionNoticeService::class);
    $showCorrectionMenu = $panel === 'agent'
        && auth()->check()
        && $correctionNotice->isMenuVisibleFor(auth()->user());

    $financialLinks = array_values(array_filter([
        Route::has("{$panel}.accounting.index")
            ? ['route' => "{$panel}.accounting.index", 'label' => __('menu.accounting'), 'icon' => 'bx-calculator']
            : null,
        $panel === 'admin' && Route::has('admin.financial-plan-templates.index')
            ? ['route' => 'admin.financial-plan-templates.index', 'label' => __('financial_plans.menu_templates'), 'icon' => 'bx-layer']
            : null,
        $panel === 'admin' && Route::has('admin.agent-financial-plans.index')
            ? ['route' => 'admin.agent-financial-plans.index', 'label' => __('financial_plans.menu_purchases'), 'icon' => 'bx-transfer']
            : null,
        $panel === 'agent' && Route::has('agent.financial-plans.index')
            ? ['route' => 'agent.financial-plans.index', 'label' => __('financial_plans.menu_agent_plans'), 'icon' => 'bx-wallet-alt']
            : null,
        $showCorrectionMenu && Route::has("{$panel}.accounting.corrections")
            ? ['route' => "{$panel}.accounting.corrections", 'label' => __('accounting_corrections.menu_label'), 'icon' => 'bx-error-circle']
            : null,
        Route::has("{$panel}.payment-requests.index")
            ? ['route' => "{$panel}.payment-requests.index", 'label' => __('menu.payment_requests'), 'icon' => 'bx-money']
            : null,
        Route::has("{$panel}.wallet.top-up.create") && module_active('payments')
            ? ['route' => "{$panel}.wallet.top-up.create", 'label' => __('payment_gateways.menu_top_up'), 'icon' => 'bx-bitcoin']
            : null,
        Route::has("{$panel}.invoices.index")
            ? ['route' => "{$panel}.invoices.index", 'label' => __('menu.invoices'), 'icon' => 'bx-file']
            : null,
        Route::has("{$panel}.client-pricing.edit")
            ? ['route' => "{$panel}.client-pricing.edit", 'label' => __('clients.display_pricing'), 'icon' => 'bx-purchase-tag']
            : null,
    ]));

    $financialOpen = request()->routeIs("{$panel}.accounting.*")
        || request()->routeIs("{$panel}.payment-requests.*")
        || request()->routeIs("{$panel}.wallet.top-up.*")
        || request()->routeIs("{$panel}.invoices.*")
        || request()->routeIs("{$panel}.client-pricing.*")
        || request()->routeIs('admin.financial-plan-templates.*')
        || request()->routeIs('admin.agent-financial-plans.*')
        || request()->routeIs('agent.financial-plans.*');
@endphp

@if ($financialLinks !== [])
    <li class="vp-nav__group" aria-expanded="{{ $financialOpen ? 'true' : 'false' }}">
        <button type="button" @class(['vp-nav__link', 'is-active' => $financialOpen])>
            <i class="bx bx-wallet vp-nav__icon"></i>
            <span class="vp-nav__label">{{ __('menu.financial') }}</span>
            <i class="bx bx-chevron-left vp-nav__arrow"></i>
        </button>
        <ul class="vp-nav__sub" style="{{ $financialOpen ? 'display:flex' : 'display:none' }}">
            @foreach ($financialLinks as $link)
                @php
                    $base = preg_replace('/\.(index|edit|create|show|update|pdf)$/', '', $link['route']);
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
