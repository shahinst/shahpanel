@extends('layouts.portal')

@section('title', $account->remote_username.' — '.config('app.name'))

@section('portal_content')
@php
    $statusClass = match (true) {
        $account->isRefunded() => 'refunded',
        $account->status->value === 'active' => 'active',
        $account->status->value === 'disabled' => 'disabled',
        $account->status->value === 'expired' => 'expired',
        $account->status->value === 'exhausted' => 'exhausted',
        default => 'disabled',
    };
    $statusLabel = $portalSnapshot['status_label'];
    $connectionState = $portalSnapshot['connection'];
    $connectionLabel = $portalSnapshot['connection_label'];
    $isSanaei = $account->service_type->isPanelV2ray();
    $usagePercent = $portalSnapshot['usage_percent'] ?? 0;
@endphp

<div class="portal-wrap">
    <header class="portal-header">
        <h1>{{ $account->remote_username }}</h1>
        <p>
            {{ $account->package?->name ?? '—' }}
            <span class="portal-badge portal-badge--{{ $statusClass }}" id="account-status-badge">{{ $statusLabel }}</span>
            <span class="portal-connection portal-connection--{{ $connectionState }}" id="connection-status">
                <span class="portal-connection__dot" aria-hidden="true"></span>
                <span class="portal-connection__label">{{ $connectionLabel }}</span>
            </span>
        </p>
    </header>

    <div class="portal-stats">
        <div class="portal-stat">
            <span class="portal-stat__label">{{ __('accounts.expires_at') }}</span>
            <span class="portal-stat__value">{{ $portalSnapshot['expiry_human'] }}</span>
        </div>
        <div class="portal-stat">
            <span class="portal-stat__label">{{ __('accounts.total_usage') }}</span>
            <span class="portal-stat__value">{{ $portalSnapshot['lifetime_used_human'] ?? $portalSnapshot['data_used_human'] }}</span>
        </div>
    </div>

    @if (! empty($portalAnnouncements))
        <div class="portal-announcements">
            @foreach ($portalAnnouncements as $announcement)
                <div class="portal-announcement">{!! $announcement['html'] !!}</div>
            @endforeach
        </div>
    @endif

    <section class="portal-card">
        <h2 class="portal-card__title">{{ __('accounts.usage_summary') }}</h2>
        @if (! $portalSnapshot['is_unlimited'])
            <div class="portal-usage-bar" aria-hidden="true">
                <div class="portal-usage-bar__fill" style="width:{{ $usagePercent }}%"></div>
            </div>
            <div class="portal-usage-detail">
                <div class="portal-usage-row portal-usage-row--highlight">
                    <span class="portal-usage-row__label">{{ __('accounts.remaining') }}</span>
                    <strong class="portal-usage-row__value">{{ $portalSnapshot['remaining_human'] }}</strong>
                </div>
                <div class="portal-usage-row">
                    <span class="portal-usage-row__label">{{ __('accounts.current_period_usage') }}</span>
                    <span class="portal-usage-row__value">{{ $portalSnapshot['data_used_human'] }}</span>
                </div>
                <div class="portal-usage-row">
                    <span class="portal-usage-row__label">{{ __('accounts.total_usage') }}</span>
                    <span class="portal-usage-row__value">{{ $portalSnapshot['lifetime_used_human'] ?? $portalSnapshot['data_used_human'] }}</span>
                </div>
                <div class="portal-usage-row">
                    <span class="portal-usage-row__label">{{ __('accounts.data_limit') }}</span>
                    <span class="portal-usage-row__value">{{ $portalSnapshot['data_limit_human'] }}</span>
                </div>
                <div class="portal-usage-row">
                    <span class="portal-usage-row__label">{{ __('accounts.usage_percent') }}</span>
                    <span class="portal-usage-row__value">{{ persian_digits(number_format((float) $usagePercent, 1)) }}٪</span>
                </div>
                <div class="portal-usage-row">
                    <span class="portal-usage-row__label">{{ __('accounts.download_usage') }}</span>
                    <span class="portal-usage-row__value">{{ $portalSnapshot['download_used_human'] }}</span>
                </div>
                <div class="portal-usage-row">
                    <span class="portal-usage-row__label">{{ __('accounts.upload_usage') }}</span>
                    <span class="portal-usage-row__value">{{ $portalSnapshot['upload_used_human'] }}</span>
                </div>
            </div>
            @if ($portalSnapshot['last_sync_human'])
                <p class="portal-usage-meta">{{ __('accounts.last_sync_at') }} · {{ $portalSnapshot['last_sync_human'] }}</p>
            @endif
        @else
            <p class="portal-usage-meta">{{ __('accounts.unlimited_data') }}</p>
            <div class="portal-usage-detail">
                <div class="portal-usage-row">
                    <span class="portal-usage-row__label">{{ __('accounts.current_period_usage') }}</span>
                    <span class="portal-usage-row__value">{{ $portalSnapshot['data_used_human'] }}</span>
                </div>
                <div class="portal-usage-row">
                    <span class="portal-usage-row__label">{{ __('accounts.total_usage') }}</span>
                    <span class="portal-usage-row__value">{{ $portalSnapshot['lifetime_used_human'] ?? $portalSnapshot['data_used_human'] }}</span>
                </div>
                <div class="portal-usage-row">
                    <span class="portal-usage-row__label">{{ __('accounts.download_usage') }}</span>
                    <span class="portal-usage-row__value">{{ $portalSnapshot['download_used_human'] }}</span>
                </div>
                <div class="portal-usage-row">
                    <span class="portal-usage-row__label">{{ __('accounts.upload_usage') }}</span>
                    <span class="portal-usage-row__value">{{ $portalSnapshot['upload_used_human'] }}</span>
                </div>
            </div>
            @if ($portalSnapshot['last_sync_human'])
                <p class="portal-usage-meta">{{ __('accounts.last_sync_at') }} · {{ $portalSnapshot['last_sync_human'] }}</p>
            @endif
        @endif
    </section>

    @if ($isSanaei && ($sanaei['subscription_link'] || $sanaei['subscription_qr']))
        <section class="portal-card">
            <h2 class="portal-card__title">{{ __('accounts.portal_subscription') }}</h2>
            <div class="portal-qr-grid">
                <div class="portal-qr-box">
                    @if ($sanaei['subscription_qr'])
                        <img src="data:image/png;base64,{{ $sanaei['subscription_qr'] }}" alt="{{ __('accounts.portal_sub_qr') }}">
                    @endif
                    @if ($sanaei['subscription_link'])
                        <input type="text" class="portal-link" id="sanaei-sub-link" value="{{ $sanaei['subscription_link'] }}" readonly>
                        <button type="button" class="portal-copy-btn" data-copy-target="sanaei-sub-link">{{ __('accounts.portal_copy') }}</button>
                    @endif
                </div>
            </div>
        </section>
    @elseif ($isSanaei)
        <section class="portal-card">
            <p class="portal-empty">{{ __('accounts.portal_sanaei_unavailable') }}</p>
        </section>
    @endif

    @if ($isWireguard)
        <section class="portal-card">
            <h2 class="portal-card__title">{{ __('accounts.config_title') }}</h2>
            @if ($config && $qrBase64)
                <div class="portal-action-row">
                    <a href="{{ route('portal.config.qr', $account->portal_token) }}" class="portal-action-btn">
                        <i class="bx bx-qr"></i> {{ __('accounts.download_qr') }}
                    </a>
                    <a href="{{ route('portal.config.download', $account->portal_token) }}" class="portal-action-btn portal-action-btn--primary">
                        <i class="bx bx-download"></i> {{ __('accounts.download_config') }}
                    </a>
                </div>
                <div class="portal-qr-grid">
                    <div class="portal-qr-box">
                        <img src="data:image/png;base64,{{ $qrBase64 }}" alt="{{ __('accounts.portal_config_qr') }}">
                    </div>
                    <div>
                        <pre class="portal-config-pre">{{ $config }}</pre>
                    </div>
                </div>
            @else
                <p class="portal-empty">{{ __('accounts.portal_wg_unavailable') }}</p>
            @endif
        </section>
    @endif

    @if (! empty($portalAppCategories))
        <div class="portal-apps-grid">
            @foreach ($portalAppCategories as $category)
                <section class="portal-card portal-apps-card">
                    <div class="portal-category-head">
                        @if (! empty($category['icon_url']))
                            <span class="portal-category-head__icon-wrap">
                                <img
                                    src="{{ $category['icon_url'] }}"
                                    alt=""
                                    class="portal-category-head__icon"
                                    loading="lazy"
                                    width="36"
                                    height="36"
                                >
                            </span>
                        @endif
                        <h2 class="portal-card__title portal-category-head__title">{{ $category['name'] }}</h2>
                    </div>
                    <div class="portal-apps-list">
                        @foreach ($category['apps'] as $app)
                            <a href="{{ $app['url'] }}" class="portal-app-tile" target="_blank" rel="noopener noreferrer">
                                <span class="portal-app-tile__icon-wrap">
                                    <img src="{{ $app['icon_url'] }}" alt="" class="portal-app-tile__icon" loading="lazy" width="44" height="44">
                                </span>
                                <span class="portal-app-tile__name">{{ $app['name'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif

    <footer class="portal-footer">{{ config('app.name') }}</footer>
</div>
@endsection

@push('scripts')
<script>
(function () {
    document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-copy-target'));
            if (!input) return;
            input.select();
            input.setSelectionRange(0, input.value.length);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value);
            } else {
                document.execCommand('copy');
            }
            btn.textContent = @json(__('accounts.portal_copied'));
            setTimeout(function () {
                btn.textContent = @json(__('accounts.portal_copy'));
            }, 1500);
        });
    });
})();
</script>
@endpush
