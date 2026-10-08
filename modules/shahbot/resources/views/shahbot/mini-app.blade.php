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
        'buy' => __('shahbot::bot.app_buy', [], 'fa'),
        'home' => __('shahbot::bot.app_tab_home', [], 'fa'),
        'shop' => __('shahbot::bot.app_tab_shop', [], 'fa'),
        'plans' => __('shahbot::bot.app_plans', [], 'fa'),
        'no_plans' => __('shahbot::bot.app_no_plans', [], 'fa'),
        'hello' => __('shahbot::bot.app_hello', [], 'fa'),
        'details' => __('shahbot::bot.app_details', [], 'fa'),
        'config' => __('shahbot::bot.app_config', [], 'fa'),
        'qr' => __('shahbot::bot.app_qr', [], 'fa'),
        'send_files' => __('shahbot::bot.app_send_files', [], 'fa'),
        'download_qr' => __('shahbot::bot.app_download_qr', [], 'fa'),
        'download_conf' => __('shahbot::bot.app_download_conf', [], 'fa'),
        'copy_config' => __('shahbot::bot.app_copy_config', [], 'fa'),
        'renew_title' => __('shahbot::bot.app_renew_title', [], 'fa'),
        'renew_none' => __('shahbot::bot.app_renew_none', [], 'fa'),
        'renew_confirm' => __('shahbot::bot.app_renew_confirm', [], 'fa'),
        'renew_low' => __('shahbot::bot.app_renew_low', [], 'fa'),
        'page' => __('shahbot::bot.app_page', [], 'fa'),
        'close' => __('shahbot::bot.app_close', [], 'fa'),
        'loading' => __('shahbot::bot.app_loading', [], 'fa'),
        'balance_now' => __('shahbot::bot.app_balance_now', [], 'fa'),
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
    {{-- The panel's own self-hosted Vazirmatn, so the app never depends on a font CDN. --}}
    @include('layouts.partials.webadmin-fonts')
    <style>
        :root {
            --bg: #0b0f1e; --bg2: #121833; --card: rgba(255,255,255,.055); --line: rgba(255,255,255,.09);
            --text: #eef1ff; --muted: #9aa3c7; --accent: {{ $accent ?? '#7c6cff' }}; --accent2: #38bdf8; --ok: #34d399; --warn: #fbbf24; --bad: #f87171;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html, body { margin: 0; min-height: 100%; }
        body {
            font-family: 'Vazirmatn', Tahoma, sans-serif; color: var(--text); font-size: 14px; line-height: 1.7;
            background: radial-gradient(120% 60% at 100% 0%, #2a2470 0%, transparent 60%),
                        radial-gradient(90% 50% at 0% 100%, #0c3b5c 0%, transparent 60%), var(--bg);
            background-attachment: fixed; padding-bottom: calc(84px + env(safe-area-inset-bottom));
        }
        .wrap { max-width: 520px; margin: 0 auto; padding: 18px 16px 8px; }
        .top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .brand { font-weight: 800; font-size: 15px; letter-spacing: -.2px; }
        .brand small { display: block; color: var(--muted); font-weight: 500; font-size: 12px; }
        .avatar { width: 40px; height: 40px; border-radius: 14px; display: grid; place-items: center; font-weight: 800;
            background: linear-gradient(135deg, var(--accent), var(--accent2)); box-shadow: 0 8px 20px -8px var(--accent); }

        .balance { position: relative; overflow: hidden; border-radius: 24px; padding: 20px; margin-bottom: 18px;
            background: linear-gradient(135deg, var(--accent) 0%, #4f46e5 45%, #0ea5e9 100%); box-shadow: 0 20px 40px -22px var(--accent); }
        .balance::after { content: ""; position: absolute; inset: auto -40px -60px auto; width: 180px; height: 180px; border-radius: 50%;
            background: rgba(255,255,255,.12); }
        .balance small { opacity: .85; font-size: 12px; }
        .balance strong { display: block; font-size: 26px; font-weight: 800; margin: 2px 0 12px; letter-spacing: -.5px; }
        .chips { display: flex; gap: 8px; position: relative; z-index: 1; }
        .chip { background: rgba(255,255,255,.18); border-radius: 999px; padding: 4px 12px; font-size: 12px; font-weight: 600; }

        .title { display: flex; align-items: center; justify-content: space-between; margin: 20px 2px 10px; font-weight: 800; font-size: 15px; }
        .count { background: var(--card); border: 1px solid var(--line); border-radius: 999px; padding: 0 10px; font-size: 12px; color: var(--muted); }

        .card { background: var(--card); border: 1px solid var(--line); border-radius: 20px; padding: 16px; margin-bottom: 12px;
            backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); animation: rise .35s ease both; }
        .card h3 { margin: 0; font-size: 15px; font-weight: 800; }
        .row { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .muted { color: var(--muted); font-size: 12.5px; }
        .badge { font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 999px; background: rgba(52,211,153,.14); color: var(--ok); }
        .badge.disabled, .badge.expired, .badge.exhausted { background: rgba(248,113,113,.14); color: var(--bad); }
        .bar { height: 8px; border-radius: 999px; background: rgba(255,255,255,.08); overflow: hidden; margin: 12px 0 6px; }
        .bar i { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, var(--accent2), var(--accent)); }
        .bar.hot i { background: linear-gradient(90deg, var(--warn), var(--bad)); }
        .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }

        .btn { border: 0; border-radius: 14px; padding: 11px 12px; font: inherit; font-weight: 700; font-size: 13px; color: #fff; cursor: pointer;
            background: linear-gradient(135deg, var(--accent), #5b5bf0); display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .btn.ghost { background: rgba(255,255,255,.07); border: 1px solid var(--line); }
        .btn:active { transform: scale(.97); }

        .group { margin: 4px 2px 8px; color: var(--muted); font-size: 12.5px; font-weight: 700; }
        .plans { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .plan { text-align: start; background: var(--card); border: 1px solid var(--line); border-radius: 18px; padding: 14px; color: var(--text);
            font: inherit; cursor: pointer; animation: rise .35s ease both; }
        .plan b { display: block; font-size: 14px; font-weight: 800; }
        .plan span { display: block; color: var(--muted); font-size: 12px; margin: 2px 0 10px; }
        .plan em { font-style: normal; font-weight: 800; color: #c7c2ff; font-size: 13.5px; }

        .empty, .error { text-align: center; color: var(--muted); padding: 36px 16px; }
        .empty b { display: block; font-size: 34px; margin-bottom: 6px; }
        .skeleton { height: 120px; border-radius: 22px; background: linear-gradient(90deg, rgba(255,255,255,.04), rgba(255,255,255,.09), rgba(255,255,255,.04));
            background-size: 200% 100%; animation: shine 1.2s linear infinite; margin-bottom: 12px; }

        .tabs { position: fixed; inset: auto 12px calc(12px + env(safe-area-inset-bottom)) 12px; max-width: 496px; margin: 0 auto; display: grid;
            grid-template-columns: repeat(3, 1fr); gap: 6px; padding: 6px; border-radius: 22px; background: rgba(18,24,51,.82);
            border: 1px solid var(--line); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); box-shadow: 0 16px 40px -20px #000; }
        .tab { border: 0; background: transparent; color: var(--muted); font: inherit; font-size: 12px; font-weight: 700; padding: 8px 4px; border-radius: 16px; cursor: pointer; }
        .tab i { display: block; font-style: normal; font-size: 18px; line-height: 1.3; }
        .tab.on { color: #fff; background: linear-gradient(135deg, rgba(124,108,255,.35), rgba(56,189,248,.25)); }
        .page { display: none; } .page.on { display: block; }

        .toast { position: fixed; left: 50%; bottom: calc(100px + env(safe-area-inset-bottom)); transform: translateX(-50%); background: #fff; color: #111;
            padding: 8px 16px; border-radius: 999px; font-weight: 700; font-size: 13px; opacity: 0; transition: opacity .2s; pointer-events: none; }
        .toast.show { opacity: .95; }
        .sheet-bg { position: fixed; inset: 0; background: rgba(3,6,18,.62); backdrop-filter: blur(4px); opacity: 0; pointer-events: none; transition: opacity .2s; z-index: 20; }
        .sheet-bg.on { opacity: 1; pointer-events: auto; }
        .sheet { position: fixed; inset: auto 0 0 0; max-width: 520px; margin: 0 auto; max-height: 88vh; overflow-y: auto; z-index: 21;
            background: #111836; border: 1px solid var(--line); border-bottom: 0; border-radius: 24px 24px 0 0; padding: 10px 16px calc(20px + env(safe-area-inset-bottom));
            transform: translateY(105%); transition: transform .25s ease; }
        .sheet.on { transform: none; }
        .grip { width: 44px; height: 5px; border-radius: 9px; background: rgba(255,255,255,.18); margin: 2px auto 12px; }
        .qr { display: block; width: 220px; max-width: 70%; margin: 6px auto 10px; background: #fff; padding: 10px; border-radius: 18px; }
        .conf { direction: ltr; text-align: left; white-space: pre-wrap; word-break: break-all; font: 12px/1.6 ui-monospace, Menlo, Consolas, monospace;
            background: rgba(0,0,0,.35); border: 1px solid var(--line); border-radius: 14px; padding: 12px; max-height: 210px; overflow: auto; margin: 6px 0 10px; }
        .sec { font-weight: 800; margin: 14px 0 6px; }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .renew { width: 100%; display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .renew[disabled] { opacity: .45; }
        .card[data-open] { cursor: pointer; }
        @keyframes rise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
        @keyframes shine { to { background-position: -200% 0; } }
        @media (prefers-reduced-motion: reduce) { .card, .plan, .skeleton { animation: none; } }
    </style>
</head>
<body>
    <div class="wrap" id="app">
        <div class="skeleton"></div><div class="skeleton" style="height:90px"></div><div class="skeleton" style="height:90px"></div>
    </div>

    <nav class="tabs" id="tabs" hidden>
        <button class="tab on" data-tab="home"><i>🏠</i>{{ $texts['home'] }}</button>
        <button class="tab" data-tab="services"><i>📦</i>{{ $texts['services'] }}</button>
        <button class="tab" data-tab="shop"><i>🛍</i>{{ $texts['shop'] }}</button>
    </nav>
    <div class="toast" id="toast"></div>
    <div class="sheet-bg" id="sheet-bg"></div>
    <div class="sheet" id="sheet"><div class="grip"></div><div id="sheet-body"></div></div>

    <script>
    (function () {
        const tg = window.Telegram && window.Telegram.WebApp;
        const app = document.getElementById('app');
        const bot = @json($botUsername);
        const t = @json($texts);
        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const fa = (n) => String(n ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
        const tap = () => { try { tg.HapticFeedback.impactOccurred('light'); } catch (e) {} };
        const openBot = () => { tap(); if (bot && tg) { tg.openTelegramLink('https://t.me/' + bot); tg.close(); } };
        const toast = (msg) => { const el = document.getElementById('toast'); el.textContent = msg; el.classList.add('show'); setTimeout(() => el.classList.remove('show'), 1600); };

        if (!tg || !tg.initData) {
            app.innerHTML = '<div class="error">' + esc(t.outside) + '</div>';
            return;
        }
        tg.ready(); tg.expand();
        try { tg.setHeaderColor('#0b0f1e'); tg.setBackgroundColor('#0b0f1e'); } catch (e) {}

        const post = (url, body) => fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(Object.assign({ initData: tg.initData }, body || {})),
        }).then(r => r.json().then(j => (r.ok ? j : Promise.reject(j.error || j.message || r.status))));
        const SERVICE_URL = @json(route('shahbot.app.service', ['bot' => $botId]));
        const load = () => post(@json(route('shahbot.app.me', ['bot' => $botId]))).then(render)
            .catch(() => { app.innerHTML = '<div class="error">' + esc(t.outside) + '</div>'; });
        load();

        function serviceCard(s, i) {
            const hot = s.percent >= 85;
            return '<div class="card" data-open="' + s.id + '" style="animation-delay:' + (i * 40) + 'ms">'
                + '<div class="row"><h3>' + esc(s.name) + '</h3><span class="badge ' + esc(s.status) + '">' + esc(s.status_label) + '</span></div>'
                + (s.package ? '<div class="muted">' + esc(s.package) + '</div>' : '')
                + '<div class="bar' + (hot ? ' hot' : '') + '"><i style="width:' + Math.max(2, Math.min(100, s.percent || 0)) + '%"></i></div>'
                + '<div class="row muted"><span>' + esc(t.used) + ': ' + fa(esc(s.used)) + ' / ' + fa(esc(s.limit)) + '</span>'
                + (s.days_left !== null && s.days_left !== undefined ? '<span>⏳ ' + fa(s.days_left) + ' ' + esc(t.days) + '</span>' : '') + '</div>'
                + (s.expiry ? '<div class="muted">' + esc(t.expiry) + ': ' + fa(esc(s.expiry)) + '</div>' : '')
                + '<div class="actions">'
                + (s.sub_url ? '<button class="btn ghost" data-copy="' + i + '">🔗 ' + esc(t.copy) + '</button>' : '<span></span>')
                + '<button class="btn" data-open="' + s.id + '">🔄 ' + esc(t.renew) + '</button></div></div>';
        }

        function render(data) {
            if (!data.registered) {
                app.innerHTML = '<div class="empty"><b>👋</b>' + esc(t.start) + '</div><div style="text-align:center"><button class="btn" data-bot>🤖 ' + esc(@json($brand)) + '</button></div>';
                bind(data);
                return;
            }
            const services = data.services || [];
            const plans = data.plans || [];
            const initial = (data.name || '?').trim().charAt(0);

            let home = '<div class="top"><div class="brand">' + (@json($logo ?? null) ? '<img src="' + esc(@json($logo ?? null)) + '" alt="" style="height:28px;vertical-align:middle;margin-inline-end:6px;border-radius:8px">' : '') + esc(@json($brand)) + '<small>' + esc(t.hello) + ' ' + esc(data.name) + '</small></div>'
                + '<div class="avatar">' + esc(initial) + '</div></div>'
                + '<div class="balance"><small>' + esc(t.balance) + '</small><strong>' + fa(esc(data.balance)) + '</strong>'
                + '<div class="chips"><span class="chip">📦 ' + fa(services.length) + ' ' + esc(t.services) + '</span>'
                + '<span class="chip">🧾 ' + fa(data.orders) + ' ' + esc(t.orders) + '</span></div></div>'
                + '<button class="btn" style="width:100%" data-bot>🛍 ' + esc(t.buy) + '</button>';
            if (services.length) {
                home += '<div class="title">' + esc(t.services) + '<span class="count">' + fa(services.length) + '</span></div>'
                    + services.slice(0, 2).map(serviceCard).join('');
            }

            let list = '<div class="title">' + esc(t.services) + '<span class="count">' + fa(services.length) + '</span></div>'
                + (services.length ? services.map(serviceCard).join('') : '<div class="empty"><b>📦</b>' + esc(t.empty) + '</div>');

            let shop = '<div class="title">' + esc(t.plans) + '</div>';
            if (!plans.length) {
                shop += '<div class="empty"><b>🛍</b>' + esc(t.no_plans) + '</div>';
            }
            plans.forEach(g => {
                shop += '<div class="group">' + esc(g.label) + '</div><div class="plans">'
                    + g.rows.map((p, i) => '<button class="plan" data-bot style="animation-delay:' + (i * 30) + 'ms"><b>' + esc(p.name) + '</b><span>' + esc(p.period) + '</span><em>' + fa(esc(p.price)) + '</em></button>').join('')
                    + '</div>';
            });

            app.innerHTML = '<section class="page on" data-page="home">' + home + '</section>'
                + '<section class="page" data-page="services">' + list + '</section>'
                + '<section class="page" data-page="shop">' + shop + '</section>';
            document.getElementById('tabs').hidden = false;
            bind(data);
        }

        function bind(data) {
            app.querySelectorAll('[data-bot]').forEach(el => el.addEventListener('click', openBot));
            app.querySelectorAll('[data-open]').forEach(el => el.addEventListener('click', (e) => {
                if (e.target.closest('[data-copy]')) return;
                e.stopPropagation(); tap(); openService(Number(el.dataset.open));
            }));
            app.querySelectorAll('[data-copy]').forEach(el => el.addEventListener('click', (e) => {
                e.stopPropagation(); tap();
                const url = data.services[Number(el.dataset.copy)].sub_url;
                navigator.clipboard.writeText(url).then(() => toast(t.copied), () => toast(url));
            }));
        }

        const sheet = document.getElementById('sheet');
        const sheetBg = document.getElementById('sheet-bg');
        const body = document.getElementById('sheet-body');
        const closeSheet = () => { sheet.classList.remove('on'); sheetBg.classList.remove('on'); try { tg.BackButton.hide(); } catch (e) {} };
        sheetBg.addEventListener('click', closeSheet);
        try { tg.BackButton.onClick(closeSheet); } catch (e) {}

        function openService(id) {
            body.innerHTML = '<div class="skeleton" style="height:220px"></div>';
            sheet.classList.add('on'); sheetBg.classList.add('on');
            try { tg.BackButton.show(); } catch (e) {}
            post(SERVICE_URL, { account: id, action: 'show' }).then(d => renderService(id, d))
                .catch(err => { body.innerHTML = '<div class="error">' + esc(typeof err === 'string' ? err : t.outside) + '</div>'; });
        }

        function download(href, name) {
            const a = document.createElement('a'); a.href = href; a.download = name; document.body.appendChild(a); a.click(); a.remove();
        }

        function renderService(id, d) {
            let h = '<div class="row"><h3>' + esc(t.details) + '</h3><button class="btn ghost" data-close>✕</button></div>';
            if (d.qr) h += '<div class="sec">' + esc(t.qr) + '</div><img class="qr" src="' + esc(d.qr) + '" alt="QR">';
            if (d.config) {
                h += '<div class="sec">' + esc(t.config) + '</div><div class="conf" id="conf">' + esc(d.config) + '</div>'
                    + '<div class="grid2"><button class="btn ghost" data-act="copy">📋 ' + esc(t.copy_config) + '</button>'
                    + (d.wireguard ? '<button class="btn ghost" data-act="dlconf">⬇️ ' + esc(t.download_conf) + '</button>' : '<span></span>') + '</div>';
                if (d.wireguard) {
                    h += '<div class="grid2" style="margin-top:8px"><button class="btn ghost" data-act="dlqr">🖼 ' + esc(t.download_qr) + '</button>'
                        + '<button class="btn" data-act="send">📩 ' + esc(t.send_files) + '</button></div>';
                }
            } else if (d.config_error) {
                h += '<div class="error">' + esc(d.config_error) + '</div>';
            }
            if (d.page) h += '<div style="margin-top:8px"><button class="btn ghost" style="width:100%" data-act="page">🌐 ' + esc(t.page) + '</button></div>';

            h += '<div class="sec">' + esc(t.renew_title) + ' <span class="muted">(' + esc(t.balance_now) + ': ' + fa(esc(d.balance)) + ')</span></div>';
            if (!d.renewals || !d.renewals.length) h += '<div class="muted">' + esc(t.renew_none) + '</div>';
            (d.renewals || []).forEach(r => {
                h += '<button class="btn renew' + (r.affordable ? '' : ' ghost') + '" data-renew="' + r.id + '" data-label="' + esc(r.period + ' — ' + r.price) + '"'
                    + (r.affordable ? '' : ' data-low="1"') + '><span>⏳ ' + esc(r.period) + '</span><b>' + fa(esc(r.price)) + '</b></button>';
            });
            body.innerHTML = h;

            body.querySelector('[data-close]').addEventListener('click', closeSheet);
            body.querySelectorAll('[data-act]').forEach(el => el.addEventListener('click', () => {
                tap();
                const act = el.dataset.act;
                if (act === 'copy') navigator.clipboard.writeText(d.config).then(() => toast(t.copied), () => toast(t.copied));
                if (act === 'dlconf') download('data:text/plain;charset=utf-8,' + encodeURIComponent(d.config), d.filename || 'wireguard.conf');
                if (act === 'dlqr') download(d.qr, (d.filename || 'wireguard').replace(/\.conf$/, '') + '.png');
                if (act === 'page') { try { tg.openLink(d.page); } catch (e) { window.open(d.page, '_blank'); } }
                if (act === 'send') {
                    el.disabled = true;
                    post(SERVICE_URL, { account: id, action: 'send' }).then(r => toast(r.message)).catch(e => toast(String(e))).finally(() => { el.disabled = false; });
                }
            }));
            body.querySelectorAll('[data-renew]').forEach(el => el.addEventListener('click', () => {
                tap();
                if (el.dataset.low) { toast(t.renew_low); return; }
                const go = () => {
                    el.disabled = true;
                    post(SERVICE_URL, { account: id, action: 'renew', duration: Number(el.dataset.renew) })
                        .then(r => { toast(r.message); try { tg.HapticFeedback.notificationOccurred('success'); } catch (e) {} closeSheet(); load(); })
                        .catch(e => { toast(String(e)); el.disabled = false; });
                };
                const q = t.renew_confirm.replace(':plan', el.dataset.label);
                try { tg.showConfirm(q, ok => ok && go()); } catch (e) { if (confirm(q)) go(); }
            }));
        }

        document.querySelectorAll('.tab').forEach(btn => btn.addEventListener('click', () => {
            tap();
            document.querySelectorAll('.tab').forEach(b => b.classList.toggle('on', b === btn));
            document.querySelectorAll('.page').forEach(p => p.classList.toggle('on', p.dataset.page === btn.dataset.tab));
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }));
    })();
    </script>
</body>
</html>
