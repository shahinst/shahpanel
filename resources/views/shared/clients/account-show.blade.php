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

<p class="mb-3">
    <a href="{{ $backUrl }}"><i class="bx bx-arrow-back"></i> {{ __('app.back') }}</a>
</p>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="panel-modern-card mb-3">
            <div class="card-head d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h3><i class="bx bx-server"></i> {{ $account->remote_username }}</h3>
                <span class="badge bg-{{ $badgeColor }}">{{ $badgeLabel }}</span>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('accounts.package') }}</dt>
                    <dd class="col-sm-8">{{ $account->package?->name ?? '—' }}</dd>
                    <dt class="col-sm-4">{{ __('accounts.expires_at') }}</dt>
                    <dd class="col-sm-8">{{ $account->expiry_at ? jalali_date($account->expiry_at) : __('accounts.no_expiry') }}</dd>
                    @if ($account->server)
                        <dt class="col-sm-4">{{ __('clients.server') }}</dt>
                        <dd class="col-sm-8">{{ $account->server->name }}</dd>
                    @endif
                    @if ($account->purchased_data_gb)
                        <dt class="col-sm-4">{{ __('accounts.renew_volume') }}</dt>
                        <dd class="col-sm-8">{{ persian_digits(rtrim(rtrim(number_format((float) $account->purchased_data_gb, 2, '.', ''), '0'), '.')) }} {{ __('accounts.gb_unit') }}</dd>
                    @endif
                    @if (!empty($kycVerification))
                        <dt class="col-sm-4">{{ __('kyc.status') }}</dt>
                        <dd class="col-sm-8">
                            <span class="badge bg-info">{{ $kycVerification->status->label() }}</span>
                            @if ($kycVerification->has_document)
                                <span class="badge bg-success">{{ __('kyc.has_document') }}</span>
                                @if (!empty($viewerIsAdmin))
                                    <a class="btn btn-sm btn-outline-primary ms-1" href="{{ route('admin.kyc.show', $kycVerification) }}">
                                        {{ __('kyc.view_document') }}
                                    </a>
                                @endif
                            @else
                                <span class="badge bg-secondary">{{ __('kyc.no_document') }}</span>
                            @endif
                        </dd>
                        <dt class="col-sm-4">{{ __('kyc.first_name') }} / {{ __('kyc.last_name') }}</dt>
                        <dd class="col-sm-8">{{ $kycVerification->fullName() }}</dd>
                        @if (!empty($viewerIsAdmin))
                            <dt class="col-sm-4">{{ __('kyc.national_code') }}</dt>
                            <dd class="col-sm-8" dir="ltr">{{ $kycVerification->national_code }}</dd>
                        @else
                            <dt class="col-sm-4">{{ __('kyc.national_code') }}</dt>
                            <dd class="col-sm-8" dir="ltr">{{ $kycVerification->maskedNationalCode() }}</dd>
                        @endif
                    @endif
                </dl>
            </div>
        </div>

        <div class="panel-modern-card mb-3">
            <div class="card-head"><h3><i class="bx bx-pie-chart-alt-2"></i> {{ __('clients.data_usage') }}</h3></div>
            <div class="card-body">
                @if ($account->isUnlimited())
                    <p class="mb-2">{{ __('clients.unlimited_data') }}</p>
                    <p class="mb-0">{{ __('accounts.total_usage') }}: <strong>{{ format_data_size($usage['used_bytes']) }}</strong></p>
                @else
                    @if ($usage['percent'] !== null)
                        <div class="progress mb-3" style="height: 10px;">
                            <div class="progress-bar @if($usage['percent'] >= 90) bg-danger @elseif($usage['percent'] >= 70) bg-warning @else bg-primary @endif"
                                 style="width: {{ $usage['percent'] }}%"></div>
                        </div>
                    @endif
                    <div class="row g-2 small">
                        <div class="col-sm-6">{{ __('accounts.data_consumed') }}: <strong>{{ format_data_size($usage['used_bytes']) }}</strong></div>
                        <div class="col-sm-6">{{ __('accounts.data_limit') }}: <strong>{{ format_data_size($usage['limit_bytes']) }}</strong></div>
                        <div class="col-sm-6">{{ __('accounts.remaining') }}: <strong>{{ format_data_size($usage['remaining_bytes']) }}</strong></div>
                        @if ($usage['percent'] !== null)
                            <div class="col-sm-6">{{ __('accounts.usage_percent') }}: <strong>{{ persian_digits(number_format($usage['percent'], 1)) }}٪</strong></div>
                        @endif
                    </div>
                @endif
            </div>
        </div>

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
                                        <th>پروتکل</th>
                                        <th style="width: 90px;">پورت</th>
                                        <th style="width: 70px;">نوع</th>
                                        <th>مورد نیاز برای اتصال</th>
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
                                <tr>
                                    <th scope="row" class="text-muted">{{ __('accounts.anyconnect_port') }}</th>
                                    <td dir="ltr"><code class="user-select-all">{{ $ac['port'] ?? 443 }}</code></td>
                                </tr>
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


        @if ($isPanelV2ray && ($panelV2ray['subscription_link'] || $panelV2ray['subscription_qr']))
            <div class="panel-modern-card mb-3">
                <div class="card-head"><h3><i class="bx bx-link"></i> {{ __('accounts.portal_subscription') }}</h3></div>
                <div class="card-body">
                    @if ($panelV2ray['subscription_qr'])
                        <div class="text-center mb-3">
                            <img src="data:image/png;base64,{{ $panelV2ray['subscription_qr'] }}" alt="QR" class="img-fluid" style="max-width: 260px;">
                        </div>
                    @endif
                    @if ($panelV2ray['subscription_link'])
                        <label class="form-label small text-muted">{{ __('accounts.subscription_link') }}</label>
                        <div class="input-group mb-2">
                            <input type="text" class="form-control" dir="ltr" readonly value="{{ $panelV2ray['subscription_link'] }}" id="sub-link-input">
                            <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('sub-link-input').value)">{{ __('clients.copy_card') }}</button>
                        </div>
                        <a href="{{ $panelV2ray['subscription_link'] }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary">{{ __('accounts.open_subscription') }}</a>
                    @endif
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
                    <p>{{ __('clients.renew_price') }}: <strong>{{ format_toman($renewalPrice) }}</strong></p>
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

        @if (! ($viewerIsClient ?? false) && ($portalIssueUrl ?? null))
            <div class="panel-modern-card mb-3">
                <div class="card-head"><h3><i class="bx bx-link-external"></i> {{ __('menu.portal_link') }}</h3></div>
                <div class="card-body">
                    <a href="{{ $portalIssueUrl }}" target="_blank" rel="noopener" class="btn btn-outline-info w-100 mb-2">
                        <i class="bx bx-link-external"></i> {{ __('menu.portal_link') }}
                    </a>
                    <p class="text-muted small mb-0">{{ __('accounts.portal_link_sms_ttl_hint', ['minutes' => persian_digits($portalLinkTtlMinutes ?? 5)]) }}</p>
                    @if ($portalActiveUrl ?? null)
                        <p class="text-muted small mt-2 mb-1">{{ __('accounts.portal_link_current_active') }}</p>
                        <code dir="ltr" class="small d-block text-break">{{ $portalActiveUrl }}</code>
                    @endif
                </div>
            </div>
        @elseif (($portalActiveUrl ?? null) && ($viewerIsClient ?? false))
            <div class="panel-modern-card mb-3">
                <div class="card-head"><h3><i class="bx bx-link-external"></i> {{ __('menu.portal_link') }}</h3></div>
                <div class="card-body">
                    <a href="{{ $portalActiveUrl }}" target="_blank" rel="noopener" class="btn btn-outline-info w-100 mb-2">{{ __('menu.portal_link') }}</a>
                    <code dir="ltr" class="small d-block text-break mb-2">{{ $portalActiveUrl }}</code>
                    <p class="text-muted small mb-0">{{ __('accounts.portal_link_sms_ttl_hint', ['minutes' => persian_digits($portalLinkTtlMinutes ?? 5)]) }}</p>
                </div>
            </div>
        @endif

        <div class="panel-modern-card mb-3">
            <div class="card-head"><h3><i class="bx bx-receipt"></i> {{ __('clients.purchase_history') }}</h3></div>
            <div class="card-body">
                @if ($purchaseInvoice)
                    <div class="border rounded p-2 mb-2">
                        <div class="small text-muted">{{ __('clients.tx_purchase') }}</div>
                        <div class="fw-semibold">{{ format_toman($purchaseInvoice->total) }}</div>
                        <div class="small text-muted">{{ jalali_date($purchaseInvoice->issued_at, 'Y/m/d H:i') }}</div>
                    </div>
                @endif
                @forelse ($renewalInvoices as $invoice)
                    <div class="border rounded p-2 mb-2">
                        <div class="small text-muted">{{ __('clients.tx_renewal') }}</div>
                        <div class="fw-semibold">{{ format_toman($invoice->total) }}</div>
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
                                {{ format_toman($tx->amount) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

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
