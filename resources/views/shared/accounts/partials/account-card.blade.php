{{--
    The account card: the service as a business card, the same for every
    service type, and exportable as a PNG the seller sends to the customer.
    It only reads what the detail page already has, so it can never show
    something the viewer could not see elsewhere on this page. The image is
    drawn in the browser from the very strings rendered here, so dates and
    digits come out exactly as the page shows them (Jalali and Persian digits
    in Persian, Gregorian and Latin elsewhere).
    Styles are inline on purpose: public/build is pre-compiled, so a change in
    resources/css would not reach a customer install until someone runs npm.
--}}
@php
    $cardCategory = $account->service_type->accountCategory();
    $cardName = trim((string) ($account->display_label ?: $account->remote_username));
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

    $cardExpiry = $account->expiry_at ? jalali_date($account->expiry_at, 'Y/m/d') : __('accounts.no_expiry');
    if ($cardDaysLeft !== null) {
        $cardExpiry .= ' · '.__('accounts.card_days_left', ['days' => persian_digits($cardDaysLeft)]);
    }

    // [label, value, ltr]. Only what the customer needs to connect and to know how long it lasts.
    $cardFacts = array_values(array_filter([
        $cardHost ? [__('accounts.server_host'), $cardHost, true] : null,
        $cardUser && ($cardPass || $cardHost) ? [__('accounts.service_username'), $cardUser, true] : null,
        $cardPass ? [__('accounts.service_password'), $cardPass, true] : null,
        $cardLink ? [__('accounts.subscription_link'), $cardLink, true] : null,
        $account->package ? [__('accounts.package'), $account->package->name, false] : null,
        [__('accounts.expires_at'), $cardExpiry, false],
        [__('accounts.data_limit'), $account->isUnlimited() ? __('clients.unlimited_data') : format_data_size($usage['limit_bytes'] ?? 0), false],
    ]));

    $cardImage = [
        'brand' => app_display_name(),
        'type' => $cardCategory->label().' · '.$account->service_type->label(),
        'name' => $cardName,
        'alias' => $cardName !== $account->remote_username ? $account->remote_username : '',
        'facts' => $cardFacts,
        'qr' => $cardQr ? 'data:image/png;base64,'.$cardQr : null,
        'id' => '#'.persian_digits($account->id),
        'file' => 'account-card-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $account->remote_username).'.png',
    ];
    $cardLines = array_merge(
        [$cardImage['brand'].' · '.$cardImage['type'], __('accounts.card_name').': '.$cardName],
        array_map(fn (array $f): string => $f[0].': '.$f[1], $cardFacts),
    );
@endphp

<section class="account-card mb-4" id="account-card" aria-label="{{ __('accounts.card_title') }}" data-card='@json($cardImage)'>
    <div class="account-card__glow" aria-hidden="true"></div>
    <header class="account-card__head">
        <div class="account-card__brand-name">{{ $cardImage['brand'] }}</div>
        <div class="account-card__brand-sub">{{ $cardImage['type'] }}</div>
    </header>

    <div class="account-card__body">
        <div class="account-card__main">
            <div class="account-card__name" dir="auto">{{ $cardName }}</div>
            @if ($cardImage['alias'] !== '')
                <div class="account-card__alias" dir="ltr">{{ $cardImage['alias'] }}</div>
            @endif
            <dl class="account-card__facts">
                @foreach ($cardFacts as [$label, $value, $ltr])
                    <div><dt>{{ $label }}</dt><dd @if ($ltr) dir="ltr" @endif>@if ($ltr)<code class="user-select-all">{{ $value }}</code>@else{{ $value }}@endif</dd></div>
                @endforeach
            </dl>
        </div>
        @if ($cardQr)
            <img src="{{ $cardImage['qr'] }}" alt="QR" class="account-card__qr">
        @endif
    </div>

    <footer class="account-card__foot">
        <span class="account-card__id" dir="ltr">{{ $cardImage['id'] }}</span>
        <div class="account-card__actions">
            <button type="button" class="btn btn-sm btn-light account-card__copy" data-copy-text='@json(implode("\n", $cardLines))' data-copied-label="{{ __('accounts.card_copied') }}">
                <i class="bx bx-copy"></i> {{ __('accounts.card_copy') }}
            </button>
            <button type="button" class="btn btn-sm btn-light account-card__download">
                <i class="bx bx-download"></i> {{ __('accounts.card_download') }}
            </button>
            <button type="button" class="btn btn-sm btn-light account-card__share">
                <i class="bx bx-send"></i> {{ __('accounts.card_share') }}
            </button>
        </div>
    </footer>
</section>

@push('styles')
<style>
    .account-card { position: relative; overflow: hidden; max-width: 560px; border-radius: 20px; color: #f8fafc; background: linear-gradient(135deg, var(--hero-from, #6366f1), var(--hero-mid, #4338ca) 55%, var(--hero-to, #312e81)); box-shadow: 0 16px 40px rgba(15, 23, 42, .22); isolation: isolate; }
    .account-card__glow { position: absolute; inset: -45% auto auto -25%; width: 75%; aspect-ratio: 1; border-radius: 50%; background: radial-gradient(circle, rgba(255, 255, 255, .26), transparent 65%); pointer-events: none; z-index: -1; }
    .account-card__head { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; padding: 16px 20px 0; }
    .account-card__brand-name { font-weight: 700; font-size: 1rem; }
    .account-card__brand-sub { font-size: .78rem; opacity: .85; }
    .account-card__body { display: flex; align-items: flex-start; gap: 18px; padding: 10px 20px 16px; }
    .account-card__main { flex: 1 1 auto; min-width: 0; }
    .account-card__name { font-size: 1.4rem; font-weight: 800; line-height: 1.25; overflow-wrap: anywhere; }
    .account-card__alias { font-size: .8rem; opacity: .8; }
    .account-card__facts { display: grid; gap: 8px; margin: 12px 0 0; }
    .account-card__facts > div { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; min-width: 0; border-bottom: 1px dashed rgba(255, 255, 255, .18); padding-bottom: 6px; }
    .account-card__facts > div:last-child { border-bottom: 0; padding-bottom: 0; }
    .account-card__facts dt { flex: 0 0 auto; font-size: .74rem; opacity: .78; }
    .account-card__facts dd { margin: 0; font-weight: 600; font-size: .9rem; text-align: end; overflow-wrap: anywhere; min-width: 0; }
    .account-card__facts code { color: #fff; background: rgba(15, 23, 42, .25); padding: 2px 7px; border-radius: 7px; font-size: .84rem; }
    .account-card__qr { flex: 0 0 auto; width: 128px; height: 128px; border-radius: 12px; background: #fff; padding: 6px; }
    .account-card__foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 20px; border-top: 1px solid rgba(255, 255, 255, .18); background: rgba(15, 23, 42, .18); flex-wrap: wrap; }
    .account-card__id { font-family: monospace; opacity: .8; font-size: .85rem; }
    .account-card__actions { display: flex; gap: 6px; flex-wrap: wrap; }
    .account-card .btn-light { background: rgba(255, 255, 255, .92); border: 0; color: #0f172a; }
    @media (max-width: 575.98px) { .account-card__body { flex-direction: column-reverse; align-items: center; } .account-card__main { width: 100%; } .account-card__name { font-size: 1.2rem; } }
</style>
@endpush

@push('scripts')
<script>
(function () {
    var card = document.getElementById('account-card');
    if (!card) return;
    var data = {};
    try { data = JSON.parse(card.getAttribute('data-card') || '{}'); } catch (e) { return; }

    var copyButton = card.querySelector('.account-card__copy');
    copyButton.addEventListener('click', function () {
        var text = '';
        try { text = JSON.parse(copyButton.getAttribute('data-copy-text') || '""'); } catch (e) {}
        if (!text || !navigator.clipboard) return;
        var label = copyButton.innerHTML;
        navigator.clipboard.writeText(text).then(function () {
            copyButton.innerHTML = '<i class="bx bx-check"></i> ' + copyButton.getAttribute('data-copied-label');
            setTimeout(function () { copyButton.innerHTML = label; }, 1800);
        });
    });

    // Draws the card as a PNG. The strings come from the card's own data, so
    // digits, dates and language are exactly what the page shows.
    function render() {
        var rtl = document.documentElement.getAttribute('dir') === 'rtl';
        var style = getComputedStyle(card);
        var colors = [style.getPropertyValue('--hero-from').trim() || '#6366f1', style.getPropertyValue('--hero-mid').trim() || '#4338ca', style.getPropertyValue('--hero-to').trim() || '#312e81'];
        var font = (style.fontFamily || 'Vazirmatn, Tahoma, sans-serif');
        var scale = 2, W = 1000, pad = 56, qrSize = data.qr ? 300 : 0, textW = W - pad * 2 - (qrSize ? qrSize + 40 : 0);

        var measure = document.createElement('canvas').getContext('2d');
        function wrap(text, size, weight, maxWidth) {
            measure.font = weight + ' ' + size + 'px ' + font;
            var words = String(text).split(/\s+/), lines = [], line = '';
            words.forEach(function (word) {
                var probe = line ? line + ' ' + word : word;
                if (measure.measureText(probe).width <= maxWidth || !line) {
                    line = probe;
                } else { lines.push(line); line = word; }
            });
            if (line) lines.push(line);
            // A single token longer than the line (a URL) is cut by characters.
            var out = [];
            lines.forEach(function (l) {
                while (measure.measureText(l).width > maxWidth && l.length > 1) {
                    var cut = l.length;
                    while (cut > 1 && measure.measureText(l.slice(0, cut)).width > maxWidth) cut--;
                    out.push(l.slice(0, cut)); l = l.slice(cut);
                }
                out.push(l);
            });
            return out;
        }

        var nameLines = wrap(data.name, 48, '800', textW);
        var facts = data.facts.map(function (f) { return { label: f[0], lines: wrap(f[1], 28, '600', textW), ltr: f[2] }; });
        var factsH = facts.reduce(function (h, f) { return h + 30 + f.lines.length * 38 + 22; }, 0);
        var bodyH = nameLines.length * 58 + (data.alias ? 34 : 0) + 24 + factsH;
        var H = pad + 40 + 30 + 36 + Math.max(bodyH, qrSize) + 40 + 70;

        var canvas = document.createElement('canvas');
        canvas.width = W * scale; canvas.height = H * scale;
        var ctx = canvas.getContext('2d');
        ctx.scale(scale, scale);

        var g = ctx.createLinearGradient(0, 0, W, H);
        g.addColorStop(0, colors[0]); g.addColorStop(.55, colors[1]); g.addColorStop(1, colors[2]);
        ctx.fillStyle = g;
        roundRect(ctx, 0, 0, W, H, 40); ctx.fill();
        var glow = ctx.createRadialGradient(W * .15, H * .05, 10, W * .15, H * .05, W * .55);
        glow.addColorStop(0, 'rgba(255,255,255,.28)'); glow.addColorStop(1, 'rgba(255,255,255,0)');
        ctx.fillStyle = glow; ctx.fillRect(0, 0, W, H);

        var x = rtl ? W - pad : pad;
        ctx.direction = rtl ? 'rtl' : 'ltr';
        ctx.textAlign = rtl ? 'right' : 'left';
        ctx.textBaseline = 'top';
        ctx.fillStyle = '#f8fafc';

        var y = pad;
        ctx.font = '700 30px ' + font; ctx.fillText(data.brand, x, y); y += 40;
        ctx.globalAlpha = .85; ctx.font = '400 22px ' + font; ctx.fillText(data.type, x, y); ctx.globalAlpha = 1; y += 30 + 36;

        var top = y;
        ctx.font = '800 48px ' + font;
        nameLines.forEach(function (l) { ctx.fillText(l, x, y); y += 58; });
        if (data.alias) { ctx.globalAlpha = .8; ctx.direction = 'ltr'; ctx.font = '400 24px ' + font; ctx.fillText(data.alias, x, y); ctx.direction = rtl ? 'rtl' : 'ltr'; ctx.globalAlpha = 1; y += 34; }
        y += 24;

        facts.forEach(function (f) {
            ctx.globalAlpha = .78; ctx.font = '400 21px ' + font; ctx.direction = rtl ? 'rtl' : 'ltr'; ctx.fillText(f.label, x, y); ctx.globalAlpha = 1; y += 30;
            ctx.font = '600 28px ' + font; ctx.direction = f.ltr ? 'ltr' : (rtl ? 'rtl' : 'ltr');
            f.lines.forEach(function (l) { ctx.fillText(l, x, y); y += 38; });
            y += 10;
            ctx.strokeStyle = 'rgba(255,255,255,.2)'; ctx.setLineDash([6, 8]); ctx.lineWidth = 1.5;
            ctx.beginPath(); ctx.moveTo(pad, y); ctx.lineTo(pad + textW, y); ctx.stroke(); ctx.setLineDash([]);
            y += 12;
        });
        ctx.direction = rtl ? 'rtl' : 'ltr';

        var footY = H - 70;
        ctx.fillStyle = 'rgba(15,23,42,.22)'; ctx.fillRect(0, footY, W, 70);
        ctx.fillStyle = '#f8fafc'; ctx.globalAlpha = .85; ctx.font = '400 22px monospace'; ctx.direction = 'ltr';
        ctx.textAlign = rtl ? 'right' : 'left'; ctx.fillText(data.id, x, footY + 22);
        ctx.textAlign = rtl ? 'left' : 'right'; ctx.font = '400 20px ' + font; ctx.direction = rtl ? 'rtl' : 'ltr'; ctx.fillText(data.brand, rtl ? pad : W - pad, footY + 24);
        ctx.globalAlpha = 1;

        return new Promise(function (resolve) {
            if (!data.qr) return resolve(canvas);
            var img = new Image();
            img.onload = function () {
                var qx = rtl ? pad : W - pad - qrSize, qy = top;
                ctx.fillStyle = '#fff'; roundRect(ctx, qx, qy, qrSize, qrSize, 24); ctx.fill();
                ctx.drawImage(img, qx + 14, qy + 14, qrSize - 28, qrSize - 28);
                resolve(canvas);
            };
            img.onerror = function () { resolve(canvas); };
            img.src = data.qr;
        });
    }

    function roundRect(ctx, x, y, w, h, r) {
        ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
    }

    function toBlob(canvas) {
        return new Promise(function (resolve) { canvas.toBlob(resolve, 'image/png'); });
    }

    function ready() {
        return (document.fonts && document.fonts.ready) ? document.fonts.ready : Promise.resolve();
    }

    function download(blob) {
        var url = URL.createObjectURL(blob), a = document.createElement('a');
        a.href = url; a.download = data.file; document.body.appendChild(a); a.click();
        setTimeout(function () { document.body.removeChild(a); URL.revokeObjectURL(url); }, 1000);
    }

    card.querySelector('.account-card__download').addEventListener('click', function () {
        ready().then(render).then(toBlob).then(function (blob) { if (blob) download(blob); });
    });

    // The device's own share sheet (Telegram, WhatsApp, ...) where it exists;
    // on a desktop browser without one the image is downloaded instead.
    card.querySelector('.account-card__share').addEventListener('click', function () {
        ready().then(render).then(toBlob).then(function (blob) {
            if (!blob) return;
            var file = new File([blob], data.file, { type: 'image/png' });
            if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
                navigator.share({ files: [file], title: data.name }).catch(function () {});
            } else {
                download(blob);
            }
        });
    });
})();
</script>
@endpush
