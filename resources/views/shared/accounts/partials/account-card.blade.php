{{--
    The account card: the service as a business card, the same for every
    service type. It only reads what the detail page already has, so it can
    never show something the viewer could not see elsewhere on this page.
    Styles are inline on purpose: public/build is pre-compiled, so a change in
    resources/css would not reach a customer install until someone runs npm.
--}}
@php
    use App\Enums\AccountStatus;

    $cardCategory = $account->service_type->accountCategory();
    $cardIcon = match ($cardCategory->value) {
        'wireguard' => 'bx-shield-quarter',
        'ppp' => 'bx-globe',
        'v2ray' => 'bx-bolt-circle',
        'anyconnect' => 'bx-network-chart',
        default => 'bx-server',
    };
    $cardTone = match ($account->status) {
        AccountStatus::Active => 'active',
        AccountStatus::Pending => 'pending',
        AccountStatus::Exhausted => 'warning',
        default => 'off',
    };
    $cardName = trim((string) ($account->display_label ?: $account->remote_username));
    $cardPercent = $usage['percent'] ?? null;
    $cardRing = $cardPercent === null ? 0 : (int) round(min(100, max(0, (float) $cardPercent)));
    $cardDaysLeft = $account->expiry_at ? (int) ceil(max(0, now()->diffInHours($account->expiry_at, false)) / 24) : null;

    // The address and secrets the customer types into their app, per service.
    $cardHost = null;
    $cardUser = $account->remote_username;
    $cardPass = null;
    $cardQr = null;
    $cardLink = null;

    if ($isPpp ?? false) {
        $cardHost = (string) (($ppp ?? [])['server_host'] ?? '') ?: null;
        $cardUser = (string) (($ppp ?? [])['username'] ?? $account->remote_username);
        $cardPass = (string) (($ppp ?? [])['password'] ?? '') ?: null;
    } elseif ($isAnyconnect ?? false) {
        $cardHost = (string) (($anyconnect ?? [])['server_host'] ?? '') ?: null;
        $cardUser = (string) (($anyconnect ?? [])['username'] ?? $account->remote_username);
        $cardPass = (string) (($anyconnect ?? [])['password'] ?? '') ?: null;
    } elseif ($isWireguard ?? false) {
        $cardHost = $account->server?->vpnClientEndpointHost() ?: null;
        $cardQr = ($wireguard ?? [])['qr_base64'] ?? null;
    } elseif ($isPanelV2ray ?? false) {
        $cardLink = ($panelV2ray ?? [])['subscription_link'] ?? null;
        $cardQr = ($panelV2ray ?? [])['subscription_qr'] ?? null;
    }

    $cardLines = array_filter([
        app_display_name().' · '.$cardCategory->label(),
        __('accounts.card_name').': '.$cardName,
        $cardHost ? __('accounts.server_host').': '.$cardHost : null,
        $cardUser ? __('accounts.service_username').': '.$cardUser : null,
        $cardPass ? __('accounts.service_password').': '.$cardPass : null,
        $cardLink ? __('accounts.subscription_link').': '.$cardLink : null,
        $account->package ? __('accounts.package').': '.$account->package->name : null,
        __('accounts.expires_at').': '.($account->expiry_at ? jalali_date($account->expiry_at) : __('accounts.no_expiry')),
        $account->isUnlimited()
            ? __('accounts.data_limit').': '.__('clients.unlimited_data')
            : __('accounts.remaining').': '.format_data_size($usage['remaining_bytes'] ?? 0).' / '.format_data_size($usage['limit_bytes'] ?? 0),
    ]);
@endphp

<section class="account-card account-card--{{ $cardTone }} mb-4" id="account-card" aria-label="{{ __('accounts.card_title') }}">
    <div class="account-card__glow" aria-hidden="true"></div>
    <header class="account-card__head">
        <div class="account-card__brand">
            <span class="account-card__chip"><i class="bx {{ $cardIcon }}"></i></span>
            <div>
                <div class="account-card__brand-name">{{ app_display_name() }}</div>
                <div class="account-card__brand-sub">{{ $cardCategory->label() }} · {{ $account->service_type->label() }}</div>
            </div>
        </div>
        <span class="account-card__status">
            <i class="bx bxs-circle"></i> {{ $badgeLabel }}
        </span>
    </header>

    <div class="account-card__body">
        <div class="account-card__main">
            <div class="account-card__name" dir="auto">{{ $cardName }}</div>
            @if ($cardName !== $account->remote_username)
                <div class="account-card__alias" dir="ltr">{{ $account->remote_username }}</div>
            @endif

            <dl class="account-card__facts">
                @if ($cardHost)
                    <div><dt>{{ __('accounts.server_host') }}</dt><dd dir="ltr"><code class="user-select-all">{{ $cardHost }}</code></dd></div>
                @endif
                @if ($cardUser && ($cardPass || $cardHost))
                    <div><dt>{{ __('accounts.service_username') }}</dt><dd dir="ltr"><code class="user-select-all">{{ $cardUser }}</code></dd></div>
                @endif
                @if ($cardPass)
                    <div><dt>{{ __('accounts.service_password') }}</dt><dd dir="ltr"><code class="user-select-all">{{ $cardPass }}</code></dd></div>
                @endif
                @if ($cardLink)
                    <div class="account-card__fact--wide"><dt>{{ __('accounts.subscription_link') }}</dt><dd dir="ltr"><code class="user-select-all account-card__link">{{ $cardLink }}</code></dd></div>
                @endif
                <div><dt>{{ __('accounts.package') }}</dt><dd>{{ $account->package?->name ?? '—' }}</dd></div>
                @if ($account->server)
                    <div><dt>{{ __('accounts.server') }}</dt><dd>{{ $account->server->name }}</dd></div>
                @endif
                <div><dt>{{ __('accounts.expires_at') }}</dt><dd>{{ $account->expiry_at ? jalali_date($account->expiry_at) : __('accounts.no_expiry') }}@if ($cardDaysLeft !== null) <small class="account-card__muted">({{ __('accounts.card_days_left', ['days' => persian_digits($cardDaysLeft)]) }})</small>@endif</dd></div>
                <div><dt>{{ __('accounts.created_at') }}</dt><dd>{{ jalali_date($account->created_at, 'Y/m/d') }}</dd></div>
                @if (! ($viewerIsClient ?? false) && $account->ownerSeller)
                    <div><dt>{{ __('accounts.card_owner') }}</dt><dd>{{ $account->ownerSeller->full_name }}</dd></div>
                @endif
            </dl>
        </div>

        <aside class="account-card__side">
            @if ($cardQr)
                <img src="data:image/png;base64,{{ $cardQr }}" alt="QR" class="account-card__qr">
            @else
                <div class="account-card__ring" style="--p: {{ $cardRing }};" role="img" aria-label="{{ __('accounts.usage_percent') }}">
                    <svg viewBox="0 0 120 120" aria-hidden="true">
                        <circle class="account-card__ring-track" cx="60" cy="60" r="52"></circle>
                        <circle class="account-card__ring-bar" cx="60" cy="60" r="52" pathLength="100"></circle>
                    </svg>
                    <div class="account-card__ring-text">
                        @if ($account->isUnlimited())
                            <strong>∞</strong><small>{{ __('clients.unlimited_data') }}</small>
                        @elseif ($cardPercent !== null)
                            <strong>{{ persian_digits($cardRing) }}٪</strong><small>{{ __('accounts.data_consumed') }}</small>
                        @else
                            <strong>—</strong>
                        @endif
                    </div>
                </div>
            @endif
            <div class="account-card__data">
                <div><span>{{ __('accounts.data_consumed') }}</span><strong>{{ format_data_size($usage['used_bytes'] ?? 0) }}</strong></div>
                @unless ($account->isUnlimited())
                    <div><span>{{ __('accounts.remaining') }}</span><strong>{{ format_data_size($usage['remaining_bytes'] ?? 0) }}</strong></div>
                    <div><span>{{ __('accounts.data_limit') }}</span><strong>{{ format_data_size($usage['limit_bytes'] ?? 0) }}</strong></div>
                @endunless
            </div>
        </aside>
    </div>

    <footer class="account-card__foot">
        <span class="account-card__id" dir="ltr">#{{ persian_digits($account->id) }}</span>
        <div class="account-card__actions">
            <button type="button" class="btn btn-sm btn-light account-card__copy" data-copy-text='@json(implode("\n", $cardLines))' data-copied-label="{{ __('accounts.card_copied') }}">
                <i class="bx bx-copy"></i> {{ __('accounts.card_copy') }}
            </button>
            <button type="button" class="btn btn-sm btn-light" onclick="window.print()">
                <i class="bx bx-printer"></i> {{ __('accounts.card_print') }}
            </button>
        </div>
    </footer>
</section>

@push('styles')
<style>
    .account-card { position: relative; overflow: hidden; border-radius: 22px; color: #f8fafc; background: linear-gradient(135deg, var(--hero-from, #6366f1), var(--hero-mid, #4338ca) 55%, var(--hero-to, #312e81)); box-shadow: 0 18px 44px rgba(15, 23, 42, .22); isolation: isolate; }
    .account-card--off { background: linear-gradient(135deg, #64748b, #334155 55%, #0f172a); }
    .account-card--warning { background: linear-gradient(135deg, #f59e0b, #d97706 55%, #92400e); }
    .account-card--pending { background: linear-gradient(135deg, #06b6d4, #0891b2 55%, #164e63); }
    .account-card__glow { position: absolute; inset: -40% auto auto -20%; width: 70%; aspect-ratio: 1; border-radius: 50%; background: radial-gradient(circle, rgba(255, 255, 255, .28), transparent 65%); pointer-events: none; z-index: -1; }
    .account-card__head, .account-card__foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 22px; }
    .account-card__foot { border-top: 1px solid rgba(255, 255, 255, .18); background: rgba(15, 23, 42, .18); }
    .account-card__brand { display: flex; align-items: center; gap: 12px; }
    .account-card__chip { display: grid; place-items: center; width: 46px; height: 46px; border-radius: 14px; font-size: 1.5rem; background: rgba(255, 255, 255, .2); box-shadow: inset 0 1px 0 rgba(255, 255, 255, .35); }
    .account-card__brand-name { font-weight: 700; font-size: 1.05rem; }
    .account-card__brand-sub { font-size: .8rem; opacity: .85; }
    .account-card__status { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; font-size: .82rem; font-weight: 600; background: rgba(255, 255, 255, .18); }
    .account-card__status .bx { font-size: .6rem; color: #86efac; }
    .account-card--off .account-card__status .bx { color: #fca5a5; }
    .account-card--warning .account-card__status .bx, .account-card--pending .account-card__status .bx { color: #fde68a; }
    .account-card__body { display: grid; grid-template-columns: 1fr auto; gap: 22px; padding: 4px 22px 20px; }
    .account-card__name { font-size: 1.6rem; font-weight: 800; letter-spacing: .01em; line-height: 1.2; word-break: break-all; }
    .account-card__alias { font-size: .85rem; opacity: .8; }
    .account-card__facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px 18px; margin: 16px 0 0; }
    .account-card__facts > div { min-width: 0; }
    .account-card__fact--wide { grid-column: 1 / -1; }
    .account-card__facts dt { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; opacity: .75; margin-bottom: 2px; }
    .account-card__facts dd { margin: 0; font-weight: 600; font-size: .95rem; overflow-wrap: anywhere; }
    .account-card__facts code { color: #fff; background: rgba(15, 23, 42, .25); padding: 2px 8px; border-radius: 8px; font-size: .9rem; }
    .account-card__link { display: inline-block; max-width: 100%; }
    .account-card__muted { opacity: .8; font-weight: 400; }
    .account-card__side { display: flex; flex-direction: column; align-items: center; gap: 12px; min-width: 150px; }
    .account-card__qr { width: 132px; height: 132px; border-radius: 14px; background: #fff; padding: 6px; }
    .account-card__ring { position: relative; width: 132px; height: 132px; }
    .account-card__ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
    .account-card__ring-track { fill: none; stroke: rgba(255, 255, 255, .22); stroke-width: 10; }
    .account-card__ring-bar { fill: none; stroke: #fff; stroke-width: 10; stroke-linecap: round; stroke-dasharray: var(--p) 100; transition: stroke-dasharray .8s ease; }
    .account-card__ring-text { position: absolute; inset: 0; display: grid; place-content: center; text-align: center; }
    .account-card__ring-text strong { font-size: 1.5rem; line-height: 1; }
    .account-card__ring-text small { font-size: .68rem; opacity: .8; }
    .account-card__data { display: grid; gap: 4px; width: 100%; font-size: .8rem; }
    .account-card__data div { display: flex; justify-content: space-between; gap: 10px; }
    .account-card__data span { opacity: .8; }
    .account-card__id { font-family: monospace; opacity: .8; }
    .account-card__actions { display: flex; gap: 8px; }
    .account-card .btn-light { background: rgba(255, 255, 255, .92); border: 0; color: #0f172a; }
    @media (max-width: 575.98px) { .account-card__body { grid-template-columns: 1fr; } .account-card__side { flex-direction: row; justify-content: space-between; } .account-card__name { font-size: 1.3rem; } }
    @media print {
        body * { visibility: hidden !important; }
        #account-card, #account-card * { visibility: visible !important; }
        #account-card { position: fixed; inset: 20px auto auto 20px; width: calc(100% - 40px); box-shadow: none; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        #account-card .account-card__actions { display: none !important; }
    }
</style>
@endpush

@push('scripts')
<script>
    document.querySelectorAll('.account-card__copy').forEach(function (button) {
        button.addEventListener('click', function () {
            var text = '';
            try { text = JSON.parse(button.getAttribute('data-copy-text') || '""'); } catch (e) {}
            if (!text || !navigator.clipboard) return;
            var label = button.innerHTML;
            navigator.clipboard.writeText(text).then(function () {
                button.innerHTML = '<i class="bx bx-check"></i> ' + button.getAttribute('data-copied-label');
                setTimeout(function () { button.innerHTML = label; }, 1800);
            });
        });
    });
</script>
@endpush
