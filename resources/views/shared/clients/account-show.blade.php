@extends('layouts.panel')

@section('page_title', $account->remote_username)

@section('panel_content')
@php
    use App\Enums\AccountStatus;
    use App\Enums\InvoiceType;

    $statusBadge = match ($account->status) {
        AccountStatus::Active => ['success', __('accounts.status_active')],
        AccountStatus::Disabled => ['secondary', __('accounts.status_disabled')],
        AccountStatus::Expired => ['danger', __('accounts.status_expired')],
        AccountStatus::Exhausted => ['warning', __('accounts.status_exhausted')],
        AccountStatus::Pending => ['info', __('accounts.status_pending')],
    };
    [$badgeColor, $badgeLabel] = $statusBadge;
@endphp

@php
    $pageDaysLeft = $account->expiry_at ? (int) ceil(max(0, now()->diffInHours($account->expiry_at, false)) / 24) : null;
    $pagePercent = $usage['percent'] ?? null;
    $pageTone = $pagePercent === null ? 'ok' : ($pagePercent >= 90 ? 'danger' : ($pagePercent >= 70 ? 'warn' : 'ok'));
    $pageExpiryTone = $pageDaysLeft === null ? 'ok' : ($pageDaysLeft <= 0 ? 'danger' : ($pageDaysLeft <= 3 ? 'warn' : 'ok'));
    $pageVolume = $account->purchased_data_gb
        ? persian_digits(rtrim(rtrim(number_format((float) $account->purchased_data_gb, 2, '.', ''), '0'), '.')).' '.__('accounts.gb_unit')
        : null;
@endphp

{{-- Header: who this account is, at a glance. The chips repeat what the
     tables below say in detail, so a reader can stop here. --}}
<header class="acct-head">
    <a href="{{ $backUrl }}" class="acct-head__back" aria-label="{{ __('app.back') }}" title="{{ __('app.back') }}"><i class="bx bx-arrow-back"></i></a>
    <div class="acct-head__title">
        <h2 dir="auto">{{ $account->display_label ?: $account->remote_username }}</h2>
        <div class="acct-head__chips">
            @if ($account->display_label)
                <span class="acct-chip acct-chip--mono" dir="ltr">{{ $account->remote_username }}</span>
            @endif
            <span class="acct-chip"><i class="bx bx-layer"></i> {{ $account->service_type->label() }}</span>
            @if ($account->server && ! ($viewerIsClient ?? false))
                <span class="acct-chip"><i class="bx bx-server"></i> {{ $account->server->name }}</span>
            @endif
            <span class="acct-chip"><i class="bx bx-calendar-plus"></i> {{ jalali_date($account->created_at, 'Y/m/d') }}</span>
        </div>
    </div>
    <span class="acct-status acct-status--{{ $badgeColor }}"><i class="bx bxs-circle"></i> {{ $badgeLabel }}</span>
</header>

<div class="acct-hero">
    @include('shared.accounts.partials.account-card')

    <div class="acct-kpis">
        <div class="panel-modern-card acct-kpi acct-kpi--{{ $pageExpiryTone }}">
            <span class="acct-kpi__icon"><i class="bx bx-time-five"></i></span>
            <span class="acct-kpi__label">{{ __('accounts.expires_at') }}</span>
            <strong class="acct-kpi__value">{{ $account->expiry_at ? jalali_date($account->expiry_at, 'Y/m/d') : __('accounts.no_expiry') }}</strong>
            @if ($pageDaysLeft !== null)
                <span class="acct-kpi__hint">{{ __('accounts.card_days_left', ['days' => persian_digits($pageDaysLeft)]) }} · {{ jalali_date($account->expiry_at, 'H:i') }}</span>
            @endif
        </div>

        <div class="panel-modern-card acct-kpi acct-kpi--{{ $pageTone }}">
            <span class="acct-kpi__icon"><i class="bx bx-pie-chart-alt-2"></i></span>
            <span class="acct-kpi__label">{{ __('clients.data_usage') }}</span>
            @if ($account->isUnlimited())
                <strong class="acct-kpi__value">{{ __('clients.unlimited_data') }}</strong>
                <span class="acct-kpi__hint">{{ __('accounts.total_usage') }}: {{ format_data_size($usage['used_bytes']) }}</span>
            @else
                <strong class="acct-kpi__value">{{ format_data_size($usage['used_bytes']) }} <small>/ {{ format_data_size($usage['limit_bytes']) }}</small></strong>
                @if ($pagePercent !== null)
                    <div class="acct-meter" role="progressbar" aria-valuenow="{{ (int) $pagePercent }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ min(100, max(0, (float) $pagePercent)) }}%"></span></div>
                    <span class="acct-kpi__hint">{{ __('accounts.remaining') }}: {{ format_data_size($usage['remaining_bytes']) }} · {{ persian_digits(number_format($pagePercent, 1)) }}٪</span>
                @endif
            @endif
        </div>

        <div class="panel-modern-card acct-kpi">
            <span class="acct-kpi__icon"><i class="bx bx-package"></i></span>
            <span class="acct-kpi__label">{{ __('accounts.package') }}</span>
            <strong class="acct-kpi__value" dir="auto">{{ $account->package?->name ?? '—' }}</strong>
            @if ($pageVolume)
                <span class="acct-kpi__hint">{{ __('accounts.renew_volume') }}: {{ $pageVolume }}</span>
            @endif
        </div>

        @if (! empty($kycVerification))
            <div class="panel-modern-card acct-kpi">
                <span class="acct-kpi__icon"><i class="bx bx-id-card"></i></span>
                <span class="acct-kpi__label">{{ __('kyc.status') }}</span>
                <strong class="acct-kpi__value" dir="auto">{{ $kycVerification->fullName() }}</strong>
                <span class="acct-kpi__hint">
                    <span class="badge bg-info">{{ $kycVerification->status->label() }}</span>
                    @if ($kycVerification->has_document)
                        <span class="badge bg-success">{{ __('kyc.has_document') }}</span>
                        @if (!empty($viewerIsAdmin))
                            <a class="acct-link" href="{{ route('admin.kyc.show', $kycVerification) }}">{{ __('kyc.view_document') }}</a>
                        @endif
                    @else
                        <span class="badge bg-secondary">{{ __('kyc.no_document') }}</span>
                    @endif
                    <span dir="ltr" class="acct-chip--mono">{{ !empty($viewerIsAdmin) ? $kycVerification->national_code : $kycVerification->maskedNationalCode() }}</span>
                </span>
            </div>
        @endif
    </div>
</div>

<div class="row g-4 acct-grid">
    <div class="col-xl-8">
        @if ($isWireguard)
            <div class="panel-modern-card mb-3">
                <div class="card-head"><h3><i class="bx bx-qr"></i> WireGuard</h3></div>
                <div class="card-body">
                    @if ($wireguard['error'])
                        <div class="alert alert-warning mb-0">{{ $wireguard['error'] }}</div>
                    @else
                        @if ($wireguard['qr_base64'])
                            <div class="text-center mb-3">
                                <img src="data:image/png;base64,{{ $wireguard['qr_base64'] }}" alt="QR" class="img-fluid" style="max-width: 260px;">
                            </div>
                        @endif
                        @if ($wireguard['config'])
                            <pre class="bg-light border rounded p-3 small mb-3" dir="ltr" style="white-space: pre-wrap;">{{ $wireguard['config'] }}</pre>
                        @endif
                        <div class="d-flex flex-wrap gap-2">
                            @if ($wireguard['config_download_route'])
                                <a href="{{ $wireguard['config_download_route'] }}" class="btn btn-sm btn-primary"><i class="bx bx-download"></i> {{ __('accounts.download_config') }}</a>
                            @endif
                            @if ($wireguard['qr_download_route'])
                                <a href="{{ $wireguard['qr_download_route'] }}" class="btn btn-sm btn-outline-primary"><i class="bx bx-qr"></i> {{ __('accounts.download_qr') }}</a>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if ($isPpp ?? false)
            @php
                $pppData = $ppp ?? [];
                $pppIpsecSecret = trim((string) ($pppData['ipsec_secret'] ?? ''));
                $pppServices = $pppData['services'] ?? [];
                $pppHost = (string) ($pppData['server_host'] ?? '');
                $pppUser = (string) ($pppData['username'] ?? $account->remote_username ?? '—');
                $pppPass = (string) ($pppData['password'] ?? '—');
            @endphp
            <div class="panel-modern-card mb-3">
                <div class="card-head"><h3><i class="bx bx-globe"></i> {{ __('accounts.connection_info') }}</h3></div>
                <div class="card-body py-3">

                    {{-- The same credentials work on every protocol; saying so up front
                         is what stops this page from being confusing. --}}
                    <div class="alert alert-info small mb-3">
                        <i class="bx bx-info-circle align-middle"></i>
                        {{ __('accounts.connection_info_multi_protocol_hint') }}
                    </div>

                    <h6 class="account-ppp-section-title">{{ __('accounts.connection_shared_credentials') }}</h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered table-striped mb-0 account-ppp-details-table">
                            <tbody>
                                <tr>
                                    <th scope="row" class="text-muted" style="width: 200px;">{{ __('accounts.server_host') }}</th>
                                    <td dir="ltr"><code class="user-select-all">{{ $pppHost !== '' ? $pppHost : '—' }}</code></td>
                                </tr>
                                <tr>
                                    <th scope="row" class="text-muted">{{ __('accounts.service_username') }}</th>
                                    <td dir="ltr"><code class="user-select-all">{{ $pppUser }}</code></td>
                                </tr>
                                <tr>
                                    <th scope="row" class="text-muted">{{ __('accounts.service_password') }}</th>
                                    <td dir="ltr"><code class="user-select-all">{{ $pppPass }}</code></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <h6 class="account-ppp-section-title">{{ __('accounts.connection_available_protocols') }}</h6>
                    @if (empty($pppServices))
                        <p class="text-muted small mb-0">{{ __('accounts.connection_no_protocols') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0 account-ppp-details-table">
                                <thead>
                                    <tr>
                                        <th>{{ __('ui.col_protocol') }}</th>
                                        <th style="width: 90px;">{{ __('ui.col_port') }}</th>
                                        <th style="width: 70px;">{{ __('ui.col_type') }}</th>
                                        <th>{{ __('ui.col_required_for_connection') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($pppServices as $svc)
                                        <tr>
                                            <td>
                                                <i class="bx {{ $svc['icon'] }} align-middle"></i>
                                                <strong>{{ $svc['label'] }}</strong>
                                            </td>
                                            <td dir="ltr"><code class="user-select-all">{{ $svc['port'] }}</code></td>
                                            <td dir="ltr" class="text-muted small">{{ $svc['transport'] }}</td>
                                            <td class="small">
                                                @if (! empty($svc['needs_ipsec']))
                                                    @if ($pppIpsecSecret !== '')
                                                        <span class="text-muted">{{ __('accounts.connection_ipsec_secret_label') }}</span>
                                                        <code class="user-select-all" dir="ltr">{{ $pppIpsecSecret }}</code>
                                                    @else
                                                        <span class="text-muted">{{ __('accounts.ipsec_without_secret') }}</span>
                                                    @endif
                                                @elseif (! empty($svc['needs_file']))
                                                    @if (! empty($pppData['ovpn_download_route']))
                                                        <a href="{{ $pppData['ovpn_download_route'] }}" class="btn btn-sm btn-primary">
                                                            <i class="bx bx-download"></i> {{ __('accounts.download_openvpn_profile_btn') }}
                                                        </a>
                                                    @else
                                                        <span class="text-muted">{{ __('accounts.ovpn_profile_not_available') }}</span>
                                                    @endif
                                                @else
                                                    <span class="text-muted">{{ __('accounts.connection_credentials_only') }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if (! ($viewerIsClient ?? false) && ! empty($pppData['l2tp_setup_guide_text']))
                        <div class="mt-3">
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    id="copy-l2tp-setup-btn"
                                    data-copy-text='@json($pppData['l2tp_setup_guide_text'])'
                                    data-copied-label="{{ __('accounts.copy_l2tp_vpn_config_copied') }}"
                                    data-failed-label="{{ __('accounts.copy_l2tp_vpn_config_failed') }}">
                                <i class="bx bx-copy"></i> {{ __('accounts.copy_l2tp_vpn_config') }}
                            </button>
                            <span class="text-muted small ms-2">{{ __('accounts.connection_l2tp_iphone_hint') }}</span>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        
        @if ($isAnyconnect ?? false)
            @php $ac = $anyconnect ?? []; @endphp
            <div class="panel-modern-card mb-3">
                <div class="card-head"><h3><i class="bx bx-network-chart"></i> {{ __('accounts.anyconnect_connection_info') }}</h3></div>
                <div class="card-body py-3">
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered table-striped mb-0">
                            <tbody>
                                <tr>
                                    <th scope="row" class="text-muted" style="width: 200px;">{{ __('accounts.anyconnect_server_address') }}</th>
                                    <td dir="ltr"><code class="user-select-all">{{ $ac['server_host'] ?? '—' }}</code></td>
                                </tr>
                                @if (($ac['show_port'] ?? true) && ! empty($ac['port']))
                                <tr>
                                    <th scope="row" class="text-muted">{{ __('accounts.anyconnect_port') }}</th>
                                    <td dir="ltr"><code class="user-select-all">{{ $ac['port'] }}</code></td>
                                </tr>
                                @endif
                                <tr>
                                    <th scope="row" class="text-muted">{{ __('accounts.service_username') }}</th>
                                    <td dir="ltr"><code class="user-select-all">{{ $ac['username'] ?? $account->remote_username }}</code></td>
                                </tr>
                                <tr>
                                    <th scope="row" class="text-muted">{{ __('accounts.service_password') }}</th>
                                    <td dir="ltr"><code class="user-select-all">{{ $ac['password'] ?? '—' }}</code></td>
                                </tr>
                                @if (! empty($ac['group_policy']))
                                <tr>
                                    <th scope="row" class="text-muted">{{ __('accounts.anyconnect_group_policy') }}</th>
                                    <td dir="ltr"><code class="user-select-all">{{ $ac['group_policy'] }}</code></td>
                                </tr>
                                @endif
                                @if (! empty($ac['tunnel_group']))
                                <tr>
                                    <th scope="row" class="text-muted">{{ __('accounts.anyconnect_tunnel_group') }}</th>
                                    <td dir="ltr"><code class="user-select-all">{{ $ac['tunnel_group'] }}</code></td>
                                </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                    @if (! empty($ac['setup_guide_text']) && ! ($viewerIsClient ?? false))
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                onclick="navigator.clipboard.writeText(@json($ac['setup_guide_text']))">
                            <i class="bx bx-copy"></i> {{ __('accounts.copy_anyconnect_config') }}
                        </button>
                    @endif
                </div>
            </div>
        @endif


        {{-- دو جعبه در کنار هم: نشانی سابسکرایب و لینک مستقیم کانفیگ. بخشی از
             کلاینت‌ها سابسکرایب را نمی‌فهمند و فقط با URI مستقیم وصل می‌شوند، پس
             هر دو باید همین‌جا با QR و دکمهٔ کپی جداگانه در دسترس باشند. --}}
        @if ($isPanelV2ray)
            @php
                $subscriptionLink = $panelV2ray['subscription_link'] ?? null;
                $subscriptionQr = $panelV2ray['subscription_qr'] ?? null;
                $configLinks = $panelV2ray['config_links'] ?? [];
                $hasSubscriptionBox = (bool) $subscriptionLink || (bool) $subscriptionQr;
                // اگر جعبهٔ سابسکرایب نباشد، جعبهٔ کانفیگ تمام عرض را می‌گیرد تا
                // نیمهٔ خالیِ کنارش صفحه را شکسته نشان ندهد. روی موبایل هر دو
                // ستون col-md-6 خودبه‌خود زیر هم می‌روند.
                $configBoxClass = $hasSubscriptionBox ? 'col-md-6' : 'col-12';
            @endphp
            <div class="row g-3 mb-3">
                @if ($hasSubscriptionBox)
                    <div class="col-md-6">
                        <div class="panel-modern-card h-100 mb-0">
                            <div class="card-head"><h3><i class="bx bx-link"></i> {{ __('accounts.portal_subscription') }}</h3></div>
                            <div class="card-body">
                                @if ($subscriptionQr)
                                    <div class="text-center mb-3">
                                        <img src="data:image/png;base64,{{ $subscriptionQr }}" alt="{{ __('accounts.portal_sub_qr') }}" class="img-fluid" style="max-width: 220px;">
                                    </div>
                                @endif
                                @if ($subscriptionLink)
                                    <label class="form-label small text-muted" for="sub-link-input">{{ __('accounts.subscription_link') }}</label>
                                    <div class="input-group mb-2">
                                        <input type="text" class="form-control" dir="ltr" readonly value="{{ $subscriptionLink }}" id="sub-link-input">
                                        <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('sub-link-input').value)">{{ __('clients.copy_link') }}</button>
                                    </div>
                                    <a href="{{ $subscriptionLink }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary">{{ __('accounts.open_subscription') }}</a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                <div class="{{ $configBoxClass }}">
                    <div class="panel-modern-card h-100 mb-0">
                        <div class="card-head"><h3><i class="bx bx-qr-scan"></i> {{ __('accounts.direct_config_link') }}</h3></div>
                        <div class="card-body">
                            {{-- اکانت چند-اینباندی چند لینک دارد؛ هرکدام با نام خودش،
                                 QR خودش و دکمهٔ کپی خودش پشت سر هم می‌آید تا با سه
                                 لینک هم چیزی ناقص به نظر نرسد. --}}
                            @forelse ($configLinks as $configLink)
                                <div class="@if ($loop->last) mb-0 @else mb-3 pb-3 border-bottom @endif">
                                    <div class="small text-muted text-center mb-2 text-break">{{ $configLink['remark'] }}</div>
                                    <div class="text-center mb-2">
                                        <img src="data:image/png;base64,{{ $configLink['qr'] }}" alt="{{ __('accounts.portal_config_qr') }}" class="img-fluid" style="max-width: 220px;">
                                    </div>
                                    <div class="input-group">
                                        <input type="text" class="form-control" dir="ltr" readonly value="{{ $configLink['uri'] }}" id="config-link-input-{{ $loop->index }}">
                                        <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('config-link-input-{{ $loop->index }}').value)">{{ __('clients.copy_link') }}</button>
                                    </div>
                                </div>
                            @empty
                                {{-- کش اشتراک را RefreshSubscriptionCacheJob کمی بعد از
                                     ساخت اکانت پر می‌کند؛ تا آن لحظه جعبهٔ خالی یا QR
                                     خراب نشان نمی‌دهیم. --}}
                                <p class="text-muted small mb-0">{{ __('accounts.config_link_not_ready') }}</p>
                            @endforelse

                            {{-- راه اجبارِ پشتیبانی: اکانت درون‌ریزی‌شده از پنل
                                 سنایی تا پر شدن کش هیچ کانفیگی ندارد و مشتریِ
                                 پشت خط نمی‌تواند منتظر دور بعدی زمان‌بند بماند.
                                 این فرم کار را همان‌جا و همگام اجرا می‌کند. --}}
                            @if (($configRefreshUrl ?? null) && (auth()->user()?->can('update', $account) ?? false))
                                <form method="POST" action="{{ $configRefreshUrl }}" class="mt-3">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                        <i class="bx bx-refresh"></i> {{ __('accounts.config_refresh') }}
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <div class="col-xl-4">
        @if (! ($viewerIsClient ?? false) && ! empty($clientCredentials))
            <div class="panel-modern-card mb-3">
                <div class="card-head"><h3><i class="bx bx-user-circle"></i> {{ __('accounts.client_login_info') }}</h3></div>
                <div class="card-body">
                    @if (! empty($clientCredentials['display_label']))
                        <div class="mb-2"><span class="text-muted small d-block">{{ __('accounts.display_label') }}</span><strong>{{ $clientCredentials['display_label'] }}</strong></div>
                    @endif
                    <div class="mb-2"><span class="text-muted small d-block">{{ __('auth.username') }}</span><code dir="ltr">{{ $clientCredentials['username'] }}</code></div>
                    <div class="mb-2"><span class="text-muted small d-block">{{ __('auth.email') }}</span><code dir="ltr">{{ $clientCredentials['email'] }}</code></div>
                    @if (! empty($clientCredentials['password']))
                        <div class="mb-2"><span class="text-muted small d-block">{{ __('accounts.client_portal_password') }}</span><code dir="ltr">{{ $clientCredentials['password'] }}</code></div>
                    @else
                        <p class="text-muted small mb-2">{{ __('accounts.client_portal_password_unavailable') }}</p>
                    @endif
                    <hr class="my-3">
                    <div class="mb-0"><span class="text-muted small d-block">{{ __('accounts.service_username') }}</span><code dir="ltr">{{ $clientCredentials['remote_username'] }}</code></div>
                    @if (! empty($clientCredentials['remote_password']))
                        <div class="mt-2 mb-0"><span class="text-muted small d-block">{{ __('accounts.service_password') }}</span><code dir="ltr">{{ $clientCredentials['remote_password'] }}</code></div>
                    @endif
                </div>
            </div>
        @endif

        @if ($viewerIsClient && ($canRenew ?? false) && ($renewalPrice ?? null) && ! $account->isRefunded())
            <div class="panel-modern-card mb-3">
                <div class="card-head"><h3><i class="bx bx-revision"></i> {{ __('clients.renew_account') }}</h3></div>
                <div class="card-body">
                    <p>{{ __('clients.renew_price') }}: <strong>{{ format_money($renewalPrice, $renewalCurrency) }}</strong></p>
                    <form method="POST" action="{{ route('client.accounts.renew', $account) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary w-100">{{ __('menu.renew') }}</button>
                    </form>
                </div>
            </div>
        @elseif (! ($viewerIsClient ?? false) && ($canRenew ?? false) && ($renewFormRoute ?? null))
            <div class="panel-modern-card mb-3">
                <div class="card-head"><h3><i class="bx bx-revision"></i> {{ __('clients.renew_from_panel') }}</h3></div>
                <div class="card-body">
                    <a href="{{ $renewFormRoute }}" class="btn btn-primary w-100"><i class="bx bx-revision"></i> {{ __('menu.renew') }}</a>
                </div>
            </div>
        @endif

        {{-- عمر لینک از تنظیمات پنل می‌آید؛ این پیش‌فرض فقط برای فراخوان‌هایی است
             که برچسب را پاس نمی‌دهند تا متن نیمه‌کاره چاپ نشود. --}}
        @php($portalLinkTtlLabel = $portalLinkTtlLabel ?? \App\Support\PortalLinkSettings::describe((int) ($portalLinkTtlMinutes ?? 5)))

        @if (! ($viewerIsClient ?? false) && ($portalIssueUrl ?? null))
            <div class="panel-modern-card mb-3">
                <div class="card-head"><h3><i class="bx bx-link-external"></i> {{ __('menu.portal_link') }}</h3></div>
                <div class="card-body">
                    <a href="{{ $portalIssueUrl }}" target="_blank" rel="noopener" class="btn btn-outline-info w-100 mb-2">
                        <i class="bx bx-link-external"></i> {{ __('menu.portal_link') }}
                    </a>
                    <p class="text-muted small mb-0">{{ __('accounts.portal_link_lifetime_hint', ['duration' => $portalLinkTtlLabel]) }}</p>
                    @if ($portalActiveUrl ?? null)
                        <p class="text-muted small mt-2 mb-1">{{ __('accounts.portal_link_current_active') }}</p>
                        <code dir="ltr" class="small d-block text-break">{{ $portalActiveUrl }}</code>
                        @if ($portalActiveExpiresAt ?? null)
                            <p class="text-muted small mt-1 mb-0">{{ __('accounts.portal_link_expires_at', ['time' => jalali_date($portalActiveExpiresAt, 'Y/m/d H:i')]) }}</p>
                        @endif
                    @endif
                    {{-- چرخاندن لینک یک کار عمدی است و لینکی را که مشتری دارد
                         می‌کشد؛ پس دکمه جداست، تأیید می‌خواهد و فقط به کسی نشان داده
                         می‌شود که مجوز تغییر اکانت را دارد — همان مجوزی که خاموش/روشن
                         کردن اکانت می‌خواهد. --}}
                    @if (($portalRegenerateUrl ?? null) && (auth()->user()?->can('update', $account) ?? false))
                        <hr class="my-3">
                        <p class="text-muted small mb-2">{{ __('accounts.portal_link_regenerate_hint') }}</p>
                        <form method="POST" action="{{ $portalRegenerateUrl }}" onsubmit="return confirm('{{ __('accounts.portal_link_regenerate_confirm') }}')">
                            @csrf
                            <button type="submit" class="btn btn-outline-warning w-100">
                                <i class="bx bx-refresh"></i> {{ __('accounts.portal_link_regenerate') }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @elseif (($portalActiveUrl ?? null) && ($viewerIsClient ?? false))
            <div class="panel-modern-card mb-3">
                <div class="card-head"><h3><i class="bx bx-link-external"></i> {{ __('menu.portal_link') }}</h3></div>
                <div class="card-body">
                    <a href="{{ $portalActiveUrl }}" target="_blank" rel="noopener" class="btn btn-outline-info w-100 mb-2">{{ __('menu.portal_link') }}</a>
                    <code dir="ltr" class="small d-block text-break mb-2">{{ $portalActiveUrl }}</code>
                    <p class="text-muted small mb-0">{{ __('accounts.portal_link_lifetime_hint', ['duration' => $portalLinkTtlLabel]) }}</p>
                    @if ($portalActiveExpiresAt ?? null)
                        <p class="text-muted small mt-1 mb-0">{{ __('accounts.portal_link_expires_at', ['time' => jalali_date($portalActiveExpiresAt, 'Y/m/d H:i')]) }}</p>
                    @endif
                </div>
            </div>
        @endif

        <div class="panel-modern-card mb-3">
            <div class="card-head"><h3><i class="bx bx-receipt"></i> {{ __('clients.purchase_history') }}</h3></div>
            <div class="card-body">
                @if ($purchaseInvoice)
                    <div class="border rounded p-2 mb-2">
                        <div class="small text-muted">{{ __('clients.tx_purchase') }}</div>
                        <div class="fw-semibold">{{ format_money($purchaseInvoice->total, $purchaseInvoice->currency) }}</div>
                        <div class="small text-muted">{{ jalali_date($purchaseInvoice->issued_at, 'Y/m/d H:i') }}</div>
                    </div>
                @endif
                @forelse ($renewalInvoices as $invoice)
                    <div class="border rounded p-2 mb-2">
                        <div class="small text-muted">{{ __('clients.tx_renewal') }}</div>
                        <div class="fw-semibold">{{ format_money($invoice->total, $invoice->currency) }}</div>
                        <div class="small text-muted">{{ jalali_date($invoice->issued_at, 'Y/m/d H:i') }}</div>
                    </div>
                @empty
                    @if (! $purchaseInvoice)
                        <p class="text-muted small mb-0">—</p>
                    @endif
                @endforelse
            </div>
        </div>

        @if ($recentTransactions->isNotEmpty())
            <div class="panel-modern-card">
                <div class="card-head"><h3><i class="bx bx-history"></i> {{ __('clients.wallet_transactions') }}</h3></div>
                <div class="card-body">
                    @foreach ($recentTransactions as $tx)
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <div>
                                <div class="small fw-semibold">{{ $tx->type->value }}</div>
                                <div class="text-muted small">{{ jalali_date($tx->created_at, 'Y/m/d H:i') }}</div>
                            </div>
                            <div class="fw-semibold @if((float)$tx->amount < 0) text-danger @else text-success @endif">
                                {{ format_money($tx->amount, $tx->currency ?? null) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

@push('styles')
<style>
    .acct-head { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 18px; }
    .acct-head__back { display: grid; place-items: center; flex: 0 0 auto; width: 42px; height: 42px; border-radius: 12px; background: var(--surface); border: 1px solid var(--border); color: var(--text-soft); font-size: 1.25rem; box-shadow: var(--shadow-sm); transition: transform .15s, box-shadow .15s; }
    .acct-head__back:hover { transform: translateY(-1px); box-shadow: var(--shadow); color: var(--accent); }
    html[dir="ltr"] .acct-head__back .bx { transform: rotate(180deg); }
    .acct-head__title { flex: 1 1 auto; min-width: 0; }
    .acct-head__title h2 { margin: 0 0 6px; font-size: 1.45rem; font-weight: 800; line-height: 1.25; overflow-wrap: anywhere; color: var(--text); }
    .acct-head__chips { display: flex; flex-wrap: wrap; gap: 6px; }
    .acct-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: .78rem; color: var(--text-soft); background: var(--surface-3); border: 1px solid var(--border); }
    .acct-chip .bx { font-size: .95rem; opacity: .8; }
    .acct-chip--mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .01em; }
    .acct-status { display: inline-flex; align-items: center; gap: 7px; flex: 0 0 auto; padding: 7px 14px; border-radius: 999px; font-size: .85rem; font-weight: 700; background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-sm); color: var(--text); }
    .acct-status .bx { font-size: .6rem; }
    .acct-status--success .bx { color: var(--success); } .acct-status--danger .bx { color: var(--danger); } .acct-status--warning .bx { color: var(--warning); } .acct-status--info .bx { color: var(--info); } .acct-status--secondary .bx { color: var(--muted-2); }

    .acct-hero { display: grid; grid-template-columns: minmax(0, 560px) 1fr; gap: 18px; align-items: start; margin-bottom: 22px; }
    .acct-hero .account-card { margin-bottom: 0 !important; }
    .acct-kpis { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
    .acct-kpi { position: relative; display: flex; flex-direction: column; gap: 4px; padding: 16px 18px; min-height: 124px; overflow: hidden; transition: transform .18s ease, box-shadow .18s ease; }
    .acct-kpi:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
    .acct-kpi::after { content: ''; position: absolute; inset-inline-end: -30px; top: -30px; width: 110px; height: 110px; border-radius: 50%; background: radial-gradient(circle, color-mix(in srgb, var(--kpi, var(--accent)) 22%, transparent), transparent 70%); pointer-events: none; }
    .acct-kpi--warn { --kpi: var(--warning); } .acct-kpi--danger { --kpi: var(--danger); } .acct-kpi--ok { --kpi: var(--accent); }
    .acct-kpi__icon { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 11px; font-size: 1.2rem; color: var(--kpi, var(--accent)); background: color-mix(in srgb, var(--kpi, var(--accent)) 14%, transparent); margin-bottom: 4px; }
    .acct-kpi__label { font-size: .76rem; color: var(--muted); letter-spacing: .02em; }
    .acct-kpi__value { font-size: 1.12rem; font-weight: 800; line-height: 1.3; color: var(--text); overflow-wrap: anywhere; }
    .acct-kpi__value small { font-size: .8rem; font-weight: 500; color: var(--muted); }
    .acct-kpi__hint { font-size: .78rem; color: var(--muted); display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
    .acct-link { font-size: .78rem; text-decoration: underline; color: var(--accent); }
    .acct-meter { height: 8px; border-radius: 999px; background: color-mix(in srgb, var(--kpi, var(--accent)) 14%, transparent); overflow: hidden; margin: 6px 0 2px; }
    .acct-meter span { display: block; height: 100%; border-radius: inherit; background: var(--kpi, var(--accent)); box-shadow: 0 0 10px color-mix(in srgb, var(--kpi, var(--accent)) 45%, transparent); transition: width .8s cubic-bezier(.2, .8, .2, 1); }

    /* The detail sections below share one rhythm. */
    .acct-grid .panel-modern-card { border-radius: var(--radius-lg); }
    .acct-grid .card-head h3 { display: flex; align-items: center; gap: 8px; font-size: 1rem; }
    .acct-grid .card-head h3 .bx { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 9px; font-size: 1.05rem; color: var(--accent); background: var(--accent-soft); }
    .acct-grid code { border-radius: 8px; padding: 2px 8px; }
    .acct-grid .table-sm > :not(caption) > * > * { padding: .55rem .75rem; }
    .acct-grid pre { border-radius: var(--radius); }
    @media (max-width: 1199.98px) { .acct-hero { grid-template-columns: 1fr; } }
    @media (max-width: 575.98px) { .acct-kpis { grid-template-columns: 1fr; } .acct-head { flex-wrap: wrap; } .acct-status { order: 3; } }
    @media (prefers-reduced-motion: reduce) { .acct-kpi, .acct-head__back, .acct-meter span { transition: none; } }
</style>
@endpush

@if (! ($viewerIsClient ?? false) && ($isPpp ?? false) && ! empty($ppp['l2tp_setup_guide_text'] ?? null))
    @push('scripts')
    <script>
    (function () {
        const btn = document.getElementById('copy-l2tp-setup-btn');

        if (!btn) {
            return;
        }

        const defaultHtml = btn.innerHTML;
        const copiedLabel = btn.dataset.copiedLabel || '';
        const failedLabel = btn.dataset.failedLabel || '';

        function readCopyText() {
            try {
                return JSON.parse(btn.getAttribute('data-copy-text') || '""');
            } catch (e) {
                return '';
            }
        }

        function flash(label, iconClass) {
            btn.innerHTML = '<i class="bx ' + iconClass + ' align-middle"></i> ' + label;
            btn.disabled = true;
            setTimeout(function () {
                btn.innerHTML = defaultHtml;
                btn.disabled = false;
            }, 2200);
        }

        function copyText(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(text);
            }

            const helper = document.createElement('textarea');
            helper.value = text;
            helper.setAttribute('readonly', '');
            helper.style.position = 'fixed';
            helper.style.left = '-9999px';
            document.body.appendChild(helper);
            helper.select();
            helper.setSelectionRange(0, text.length);
            const ok = document.execCommand('copy');
            document.body.removeChild(helper);

            return ok ? Promise.resolve() : Promise.reject();
        }

        btn.addEventListener('click', function () {
            const text = readCopyText();

            if (text === '') {
                return;
            }

            copyText(text).then(function () {
                flash(copiedLabel, 'bx-check');
            }).catch(function () {
                flash(failedLabel, 'bx-error');
            });
        });
    })();
    </script>
    @endpush
@endif
