@php
    $tabs = [
        ['admin.shahbot.index', 'bx-grid-alt', 'tab_dashboard', ['admin.shahbot.index']],
        ['admin.shahbot.users.index', 'bx-group', 'tab_users', ['admin.shahbot.users.*']],
        ['admin.shahbot.payments.index', 'bx-receipt', 'tab_payments', ['admin.shahbot.payments.*']],
        ['admin.shahbot.orders', 'bx-cart', 'tab_orders', ['admin.shahbot.orders']],
        ['admin.shahbot.codes.index', 'bx-purchase-tag', 'tab_codes', ['admin.shahbot.codes.*']],
        ['admin.shahbot.agents.index', 'bx-briefcase', 'tab_agents', ['admin.shahbot.agents.*']],
        ['admin.shahbot.lotteries.index', 'bx-trophy', 'tab_fun', ['admin.shahbot.lotteries.*']],
        ['admin.shahbot.broadcasts.index', 'bx-broadcast', 'tab_broadcasts', ['admin.shahbot.broadcasts.*']],
        ['admin.shahbot.tickets.index', 'bx-support', 'tab_tickets', ['admin.shahbot.tickets.*']],
        ['admin.shahbot.tutorials.index', 'bx-book-open', 'tab_tutorials', ['admin.shahbot.tutorials.*']],
        ['admin.shahbot.editor', 'bx-edit', 'tab_editor', ['admin.shahbot.editor*']],
        ['admin.shahbot.settings', 'bx-cog', 'tab_settings', ['admin.shahbot.settings*']],
    ];
    $pendingCount = \Modules\ShahBot\Models\BotPayment::query()->where('status', 'pending')->count();
    $openTickets = \Modules\ShahBot\Models\BotTicket::query()->where('status', 'open')->count();
@endphp
<div class="sb-head">
    <div>
        <h1><i class="bx bxl-telegram"></i> {{ __('shahbot::admin.title') }}</h1>
        <p>{{ __('shahbot::admin.subtitle') }}</p>
    </div>
</div>
<nav class="sb-tabs">
    @foreach ($tabs as [$route, $icon, $label, $patterns])
        <a href="{{ route($route) }}" @class(['sb-tab', 'is-active' => request()->routeIs(...$patterns)])>
            <i class="bx {{ $icon }}"></i> {{ __('shahbot::admin.'.$label) }}
            @if ($route === 'admin.shahbot.payments.index' && $pendingCount > 0)<span class="sb-badge">{{ persian_digits($pendingCount) }}</span>@endif
            @if ($route === 'admin.shahbot.tickets.index' && $openTickets > 0)<span class="sb-badge">{{ persian_digits($openTickets) }}</span>@endif
        </a>
    @endforeach
</nav>

@once
@push('styles')
<style>
    .sb-head { display:flex; justify-content:space-between; align-items:flex-end; gap:1rem; margin-bottom:1rem; }
    .sb-head h1 { font-size:1.35rem; font-weight:800; margin:0 0 .25rem; display:flex; align-items:center; gap:.5rem; }
    .sb-head h1 i { color:#229ED9; font-size:1.7rem; }
    .sb-head p { margin:0; color:var(--bs-secondary-color, #6b7280); font-size:.9rem; }
    .sb-tabs { display:flex; gap:.35rem; overflow-x:auto; padding-bottom:.4rem; margin-bottom:1.1rem; border-bottom:1px solid rgba(127,127,127,.18); }
    .sb-tab { display:inline-flex; align-items:center; gap:.35rem; white-space:nowrap; padding:.5rem .85rem; border-radius:.6rem; color:inherit; text-decoration:none; font-size:.88rem; opacity:.8; }
    .sb-tab:hover { background:rgba(34,158,217,.08); opacity:1; }
    .sb-tab.is-active { background:#229ED9; color:#fff; opacity:1; }
    .sb-badge { background:#ef4444; color:#fff; border-radius:999px; font-size:.7rem; padding:.05rem .45rem; }
    .sb-cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:.8rem; margin-bottom:1.1rem; }
    .sb-card { border:1px solid rgba(127,127,127,.18); border-radius:.9rem; padding:1rem; background:var(--bs-body-bg, #fff); display:flex; gap:.75rem; align-items:center; }
    .sb-card i { font-size:1.6rem; width:2.6rem; height:2.6rem; border-radius:.7rem; display:grid; place-items:center; color:var(--tone); background:color-mix(in srgb, var(--tone) 14%, transparent); }
    .sb-card strong { display:block; font-size:1.15rem; }
    .sb-card span { font-size:.8rem; color:var(--bs-secondary-color, #6b7280); }
    .sb-box { border:1px solid rgba(127,127,127,.18); border-radius:.9rem; background:var(--bs-body-bg, #fff); margin-bottom:1.1rem; }
    .sb-box > header { padding:.8rem 1rem; border-bottom:1px solid rgba(127,127,127,.14); font-weight:700; display:flex; justify-content:space-between; align-items:center; gap:.5rem; }
    .sb-box > .sb-body { padding:1rem; }
    .sb-box table { margin:0; }
    .sb-pill { display:inline-block; padding:.12rem .55rem; border-radius:999px; font-size:.75rem; background:rgba(127,127,127,.14); }
    .sb-pill.ok { background:#dcfce7; color:#166534; } .sb-pill.warn { background:#fef3c7; color:#92400e; } .sb-pill.bad { background:#fee2e2; color:#991b1b; } .sb-pill.info { background:#e0f2fe; color:#075985; }
    .sb-filters { display:flex; flex-wrap:wrap; gap:.4rem; margin-bottom:.8rem; }
    .sb-filters a { padding:.3rem .75rem; border-radius:999px; border:1px solid rgba(127,127,127,.25); text-decoration:none; color:inherit; font-size:.82rem; }
    .sb-filters a.is-active { background:#229ED9; border-color:#229ED9; color:#fff; }
    .sb-grid-2 { display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:1rem; }
    .sb-chart { display:flex; align-items:flex-end; gap:.35rem; height:150px; padding-top:.5rem; }
    .sb-chart div { flex:1; display:flex; flex-direction:column; align-items:center; gap:.25rem; height:100%; justify-content:flex-end; }
    .sb-chart b { width:100%; background:linear-gradient(180deg,#38bdf8,#229ED9); border-radius:.35rem .35rem 0 0; min-height:2px; }
    .sb-chart small { font-size:.65rem; color:var(--bs-secondary-color, #6b7280); }
    .sb-msg { max-width:80%; padding:.6rem .85rem; border-radius:.8rem; margin-bottom:.6rem; white-space:pre-wrap; }
    .sb-msg.user { background:rgba(127,127,127,.12); }
    .sb-msg.admin { background:#e0f2fe; color:#0c4a6e; margin-inline-start:auto; }
    .sb-msg small { display:block; opacity:.7; font-size:.72rem; margin-top:.25rem; }
    .sb-muted { color:var(--bs-secondary-color, #6b7280); font-size:.82rem; }
    @media (max-width: 576px) { .sb-head { flex-direction:column; align-items:flex-start; } }
</style>
@endpush
@endonce
