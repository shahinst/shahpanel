<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('shahbot::bot.pay_page_title', [], 'fa') }}</title>
    <style>
        :root { --bg:#f1f5f9; --card:#fff; --text:#0f172a; --muted:#64748b; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0f172a; --card:#1e293b; --text:#e2e8f0; --muted:#94a3b8; } }
        body { margin:0; min-height:100vh; display:grid; place-items:center; background:var(--bg); color:var(--text); font-family: Vazirmatn, Tahoma, sans-serif; padding:16px; box-sizing:border-box; }
        .card { background:var(--card); border-radius:18px; padding:28px 22px; max-width:380px; width:100%; text-align:center; box-shadow:0 10px 30px rgba(0,0,0,.08); }
        .icon { font-size:54px; line-height:1; }
        h1 { font-size:1.2rem; margin:14px 0 6px; }
        p { color:var(--muted); margin:0 0 18px; font-size:.95rem; line-height:1.8; }
        a.btn { display:inline-block; background:#229ED9; color:#fff; text-decoration:none; padding:12px 22px; border-radius:12px; font-weight:700; }
    </style>
</head>
<body>
    <div class="card">
        @if ($success)
            <div class="icon">✅</div>
            <h1>{{ __('shahbot::bot.pay_page_ok', [], 'fa') }}</h1>
            <p>{{ __('shahbot::bot.pay_page_ok_hint', ['amount' => format_money($payment->net_toman, 'IRT')], 'fa') }}</p>
        @elseif ($pending)
            <div class="icon">⏳</div>
            <h1>{{ __('shahbot::bot.pay_page_pending', [], 'fa') }}</h1>
            <p>{{ __('shahbot::bot.pay_page_pending_hint', [], 'fa') }}</p>
        @else
            <div class="icon">❌</div>
            <h1>{{ __('shahbot::bot.pay_page_failed', [], 'fa') }}</h1>
            <p>{{ __('shahbot::bot.pay_page_failed_hint', [], 'fa') }}</p>
        @endif
        @if ($botUrl)
            <a class="btn" href="{{ $botUrl }}">{{ __('shahbot::bot.pay_page_back', [], 'fa') }}</a>
        @endif
    </div>
</body>
</html>
