@php
    $texts = [
            'balance' => __('shahbot::bot.app_balance', [], 'fa'),
            'services' => __('shahbot::bot.app_services', [], 'fa'),
            'orders' => __('shahbot::bot.app_orders', [], 'fa'),
            'empty' => __('shahbot::bot.services_empty', [], 'fa'),
            'outside' => __('shahbot::bot.app_outside', [], 'fa'),
            'start' => __('shahbot::bot.app_start', [], 'fa'),
            'copy' => __('shahbot::bot.app_copy', [], 'fa'),
            'copied' => __('shahbot::bot.app_copied', [], 'fa'),
            'renew' => __('shahbot::bot.btn_renew', [], 'fa'),
            'expiry' => __('shahbot::bot.app_expiry', [], 'fa'),
            'days' => __('shahbot::bot.app_days', [], 'fa'),
            'used' => __('shahbot::bot.app_used', [], 'fa'),
        ];
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>{{ $brand }}</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        :root {
            --bg: var(--tg-theme-bg-color, #f1f5f9);
            --card: var(--tg-theme-secondary-bg-color, #ffffff);
            --text: var(--tg-theme-text-color, #0f172a);
            --muted: var(--tg-theme-hint-color, #64748b);
            --accent: var(--tg-theme-button-color, #229ED9);
            --accent-text: var(--tg-theme-button-text-color, #ffffff);
            --ok: #16a34a; --warn: #d97706; --bad: #dc2626;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: Vazirmatn, Tahoma, system-ui, sans-serif; font-size: 15px; padding: 14px 14px 90px; }
        .hero { background: linear-gradient(135deg, var(--accent), #6366f1); color: #fff; border-radius: 18px; padding: 18px; margin-bottom: 14px; }
        .hero small { opacity: .85; }
        .hero h1 { margin: 2px 0 12px; font-size: 1.15rem; }
        .hero .bal { display: flex; justify-content: space-between; align-items: end; }
        .hero .bal strong { font-size: 1.35rem; }
        .title { font-weight: 800; margin: 18px 4px 10px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: var(--card); border-radius: 16px; padding: 14px; margin-bottom: 12px; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
        .row { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .name { font-weight: 700; word-break: break-all; }
        .muted { color: var(--muted); font-size: .85rem; }
        .pill { font-size: .75rem; padding: 2px 10px; border-radius: 999px; background: rgba(127,127,127,.15); white-space: nowrap; }
        .pill.active { background: rgba(22,163,74,.15); color: var(--ok); }
        .pill.expired, .pill.disabled { background: rgba(220,38,38,.12); color: var(--bad); }
        .pill.exhausted, .pill.pending { background: rgba(217,119,6,.15); color: var(--warn); }
        .bar { height: 8px; border-radius: 99px; background: rgba(127,127,127,.18); overflow: hidden; margin: 10px 0 6px; }
        .bar span { display: block; height: 100%; background: var(--accent); border-radius: 99px; }
        .bar span.hot { background: var(--bad); }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: 8px; }
        .btn { display: block; width: 100%; border: 0; border-radius: 12px; padding: 11px; font: inherit; font-weight: 700; cursor: pointer; background: var(--accent); color: var(--accent-text); text-align: center; text-decoration: none; }
        .btn.ghost { background: rgba(127,127,127,.15); color: var(--text); }
        .empty, .error { text-align: center; padding: 40px 10px; color: var(--muted); }
        .empty b { display: block; font-size: 2.2rem; margin-bottom: 8px; }
        .footer { position: fixed; bottom: 0; inset-inline: 0; padding: 12px 14px calc(12px + env(safe-area-inset-bottom)); background: var(--bg); }
        .skeleton { height: 110px; border-radius: 16px; background: linear-gradient(90deg, rgba(127,127,127,.08), rgba(127,127,127,.18), rgba(127,127,127,.08)); background-size: 200% 100%; animation: sh 1.2s infinite; margin-bottom: 12px; }
        @keyframes sh { to { background-position: -200% 0; } }
        .toast { position: fixed; top: 14px; inset-inline: 14px; background: #0f172a; color: #fff; padding: 10px 14px; border-radius: 12px; text-align: center; opacity: 0; transition: opacity .2s; pointer-events: none; }
        .toast.show { opacity: .92; }
    </style>
</head>
<body>
    <div id="app">
        <div class="skeleton"></div><div class="skeleton"></div>
    </div>
    <div class="footer"><button class="btn" id="shop">🛍 {{ __('shahbot::bot.app_buy', [], 'fa') }}</button></div>
    <div class="toast" id="toast"></div>

    <script>
    (function () {
        const tg = window.Telegram && window.Telegram.WebApp;
        const app = document.getElementById('app');
        const bot = @json($botUsername);
        const t = @json($texts);
        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
        const fa = (s) => String(s ?? '').replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
        const openBot = () => { if (bot && tg) { tg.openTelegramLink('https://t.me/' + bot); tg.close(); } };
        const toast = (msg) => { const el = document.getElementById('toast'); el.textContent = msg; el.classList.add('show'); setTimeout(() => el.classList.remove('show'), 1600); };

        document.getElementById('shop').addEventListener('click', openBot);

        if (!tg || !tg.initData) {
            app.innerHTML = '<div class="error">' + esc(t.outside) + '</div>';
            return;
        }

        tg.ready();
        tg.expand();

        fetch(@json(route('shahbot.app.me', ['bot' => $botId])), {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify({initData: tg.initData}),
        }).then((r) => r.ok ? r.json() : Promise.reject(r.status)).then((data) => {
            if (!data.registered) {
                app.innerHTML = '<div class="empty"><b>👋</b>' + esc(t.start) + '</div>';
                return;
            }

            let html = '<div class="hero"><small>' + esc(@json($brand)) + '</small><h1>' + esc(data.name) + '</h1>'
                + '<div class="bal"><div><small>' + esc(t.balance) + '</small><br><strong>' + esc(data.balance) + '</strong></div>'
                + '<small>' + esc(t.orders) + ': ' + fa(data.orders) + '</small></div></div>';

            html += '<div class="title">' + esc(t.services) + ' <span class="pill">' + fa(data.services.length) + '</span></div>';

            if (data.services.length === 0) {
                html += '<div class="empty"><b>📦</b>' + esc(t.empty) + '</div>';
            }

            data.services.forEach((s, i) => {
                html += '<div class="card"><div class="row"><span class="name">' + esc(s.name) + '</span><span class="pill ' + esc(s.status) + '">' + esc(s.status_label) + '</span></div>'
                    + '<div class="muted">' + esc(s.package) + '</div>'
                    + '<div class="bar"><span class="' + (s.percent >= 90 ? 'hot' : '') + '" style="width:' + Number(s.percent) + '%"></span></div>'
                    + '<div class="row muted"><span>' + esc(t.used) + ': ' + fa(s.used) + ' / ' + fa(s.limit) + '</span>'
                    + (s.days_left !== null ? '<span>' + fa(s.days_left) + ' ' + esc(t.days) + '</span>' : '') + '</div>'
                    + (s.expiry ? '<div class="muted">' + esc(t.expiry) + ': ' + esc(s.expiry) + '</div>' : '')
                    + '<div class="grid">'
                    + (s.sub_url ? '<button class="btn ghost" data-copy="' + i + '">🔗 ' + esc(t.copy) + '</button>' : '<span></span>')
                    + '<button class="btn ghost" data-renew>🔄 ' + esc(t.renew) + '</button></div></div>';
            });

            app.innerHTML = html;

            app.querySelectorAll('[data-copy]').forEach((el) => el.addEventListener('click', () => {
                const url = data.services[Number(el.dataset.copy)].sub_url;
                (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(() => toast(t.copied), () => tg.showAlert(url));
                tg.HapticFeedback && tg.HapticFeedback.notificationOccurred('success');
            }));
            app.querySelectorAll('[data-renew]').forEach((el) => el.addEventListener('click', openBot));
        }).catch(() => {
            app.innerHTML = '<div class="error">' + esc(t.outside) + '</div>';
        });
    })();
    </script>
</body>
</html>
