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
            --text: #eef1ff; --muted: #9aa3c7; --accent: #7c6cff; --accent2: #38bdf8; --ok: #34d399; --warn: #fbbf24; --bad: #f87171;
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
            background: linear-gradient(135deg, #6d5bff 0%, #4f46e5 45%, #0ea5e9 100%); box-shadow: 0 20px 40px -22px #6d5bff; }
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

        fetch(@json(route('shahbot.app.me', ['bot' => $botId])), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ initData: tg.initData }),
        }).then(r => r.ok ? r.json() : Promise.reject(r.status)).then(render).catch(() => { app.innerHTML = '<div class="error">' + esc(t.outside) + '</div>'; });

        function serviceCard(s, i) {
            const hot = s.percent >= 85;
            return '<div class="card" style="animation-delay:' + (i * 40) + 'ms">'
                + '<div class="row"><h3>' + esc(s.name) + '</h3><span class="badge ' + esc(s.status) + '">' + esc(s.status_label) + '</span></div>'
                + (s.package ? '<div class="muted">' + esc(s.package) + '</div>' : '')
                + '<div class="bar' + (hot ? ' hot' : '') + '"><i style="width:' + Math.max(2, Math.min(100, s.percent || 0)) + '%"></i></div>'
                + '<div class="row muted"><span>' + esc(t.used) + ': ' + fa(esc(s.used)) + ' / ' + fa(esc(s.limit)) + '</span>'
                + (s.days_left !== null && s.days_left !== undefined ? '<span>⏳ ' + fa(s.days_left) + ' ' + esc(t.days) + '</span>' : '') + '</div>'
                + (s.expiry ? '<div class="muted">' + esc(t.expiry) + ': ' + fa(esc(s.expiry)) + '</div>' : '')
                + '<div class="actions">'
                + (s.sub_url ? '<button class="btn ghost" data-copy="' + i + '">🔗 ' + esc(t.copy) + '</button>' : '<span></span>')
                + '<button class="btn" data-bot>🔄 ' + esc(t.renew) + '</button></div></div>';
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

            let home = '<div class="top"><div class="brand">' + esc(@json($brand)) + '<small>' + esc(t.hello) + ' ' + esc(data.name) + '</small></div>'
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
            app.querySelectorAll('[data-copy]').forEach(el => el.addEventListener('click', () => {
                tap();
                const url = data.services[Number(el.dataset.copy)].sub_url;
                navigator.clipboard.writeText(url).then(() => toast(t.copied), () => toast(url));
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
