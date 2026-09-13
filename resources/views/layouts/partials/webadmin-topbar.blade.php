@php
    $routeName = request()->route()?->getName() ?? '';
    $routePanel = explode('.', $routeName)[0] ?: 'admin';
    $panel = in_array($routePanel, ['admin', 'agent', 'seller', 'client'], true)
        ? $routePanel
        : match (auth()->user()?->role) {
            \App\Enums\UserRole::Admin => 'admin',
            \App\Enums\UserRole::Agent => 'agent',
            \App\Enums\UserRole::Seller => 'seller',
            \App\Enums\UserRole::Client => 'client',
            default => 'admin',
        };

    $unreadCount = 0;
    $walletInfo = panel_wallet_info();
    if (auth()->check()) {
        try {
            $unreadCount = \App\Models\PanelNotification::query()
                ->where('user_id', auth()->id())
                ->where('is_read', false)
                ->count();
        } catch (\Throwable) {
            $unreadCount = 0;
        }
    }

    $paymentRoute = match ($panel) {
        'admin' => 'admin.payment-requests.index',
        'agent' => 'agent.payment-requests.index',
        'seller' => 'seller.payment-requests.index',
        'client' => 'client.payment-requests.index',
        default => null,
    };
    $profileRoute = match ($panel) {
        'admin', 'agent', 'seller' => "{$panel}.profile.edit",
        default => null,
    };
    $twoFactorRoute = match ($panel) {
        'admin', 'agent', 'seller' => "{$panel}.two-factor.show",
        default => null,
    };
@endphp

<header class="vp-topbar">
    <button type="button" class="vp-topbar__btn vp-topbar__menu-btn" data-vp-sidebar-toggle aria-label="{{ __('menu.main_menu') }}">
        <i class="bx bx-menu"></i>
    </button>

    <h1 class="vp-topbar__title text-truncate">@yield('page_title', __('menu.dashboard'))</h1>

    <div class="d-flex align-items-center gap-2 vp-topbar__actions">
        @if ($paymentRoute && \Illuminate\Support\Facades\Route::has($paymentRoute))
            <a href="{{ route($paymentRoute) }}" class="vp-topbar__wallet" title="{{ __('wallet.remaining_balance') }}">
                <i class="bx bx-wallet"></i>
                <span class="d-none d-sm-inline">
                    @forelse (($walletInfo['wallets'] ?? []) as $walletRow)
                        {{ format_money($walletRow['balance'], $walletRow['currency']) }}@if (! $loop->last) · @endif
                    @empty
                        {{ format_money($walletInfo['balance'], $walletInfo['currency'] ?? 'IRT') }}
                    @endforelse
                </span>
                @if ($walletInfo['infinite'])
                    <span class="badge bg-soft-success d-none d-md-inline">{{ __('wallet.infinite_badge') }}</span>
                @endif
            </a>
        @else
            <span class="vp-topbar__wallet" title="{{ __('wallet.remaining_balance') }}">
                <i class="bx bx-wallet"></i>
                <span class="d-none d-sm-inline">
                    @forelse (($walletInfo['wallets'] ?? []) as $walletRow)
                        {{ format_money($walletRow['balance'], $walletRow['currency']) }}@if (! $loop->last) · @endif
                    @empty
                        {{ format_money($walletInfo['balance'], $walletInfo['currency'] ?? 'IRT') }}
                    @endforelse
                </span>
            </span>
        @endif

        <a href="{{ route('notifications.index') }}" class="vp-topbar__btn" aria-label="{{ __('menu.notifications') ?? '' }}">
            <i class="bx bx-bell"></i>
            @if ($unreadCount > 0)
                <span class="vp-topbar__noti-dot">{{ persian_digits($unreadCount) }}</span>
            @endif
        </a>

        <div class="dropdown">
            <button type="button" class="vp-topbar__user" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="vp-sidebar__brand-logo" style="width:34px;height:34px;font-size:1.1rem;"><i class="bx bx-user"></i></span>
                <span class="d-none d-xl-inline-block fw-semibold">{{ auth()->user()->full_name }}</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end" style="min-width:14rem;">
                <div class="p-3 border-bottom">
                    <div class="fw-semibold">{{ auth()->user()->full_name }}</div>
                    <div class="small text-muted">{{ auth()->user()->role->label() }}</div>
                </div>
                @if ($profileRoute && \Illuminate\Support\Facades\Route::has($profileRoute))
                    <a href="{{ route($profileRoute) }}" class="dropdown-item">
                        <i class="bx bx-user"></i> {{ __('profile.menu') }}
                    </a>
                @endif
                @if ($twoFactorRoute && \Illuminate\Support\Facades\Route::has($twoFactorRoute))
                    <a href="{{ route($twoFactorRoute) }}" class="dropdown-item">
                        <i class="bx bx-shield-quarter"></i> {{ __('security.two_factor') }}
                    </a>
                @endif
                <div class="dropdown-divider"></div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="dropdown-item text-danger">
                        <i class="bx bx-log-out"></i> {{ __('auth.logout') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
