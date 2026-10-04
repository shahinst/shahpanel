@php
    $tabs = [
        ['admin.shahbot.index', 'bx-grid-alt', 'tab_dashboard', ['admin.shahbot.index']],
        ['admin.shahbot.users.index', 'bx-group', 'tab_users', ['admin.shahbot.users.*']],
        ['admin.shahbot.payments.index', 'bx-receipt', 'tab_payments', ['admin.shahbot.payments.*']],
        ['admin.shahbot.orders', 'bx-cart', 'tab_orders', ['admin.shahbot.orders']],
        ['admin.shahbot.refunds.index', 'bx-undo', 'tab_refunds', ['admin.shahbot.refunds.*']],
        ['admin.shahbot.codes.index', 'bx-purchase-tag', 'tab_codes', ['admin.shahbot.codes.*']],
        ['admin.shahbot.agents.index', 'bx-briefcase', 'tab_agents', ['admin.shahbot.agents.*']],
        ['admin.shahbot.access', 'bx-key', 'bot_access', ['admin.shahbot.access*']],
        ['admin.shahbot.lotteries.index', 'bx-trophy', 'tab_fun', ['admin.shahbot.lotteries.*']],
        ['admin.shahbot.broadcasts.index', 'bx-broadcast', 'tab_broadcasts', ['admin.shahbot.broadcasts.*']],
        ['admin.shahbot.tickets.index', 'bx-support', 'tab_tickets', ['admin.shahbot.tickets.*']],
        ['admin.shahbot.tutorials.index', 'bx-book-open', 'tab_tutorials', ['admin.shahbot.tutorials.*']],
        ['admin.shahbot.editor', 'bx-edit', 'tab_editor', ['admin.shahbot.editor*']],
        ['admin.shahbot.settings', 'bx-cog', 'tab_settings', ['admin.shahbot.settings*']],
    ];
    $pendingCount = \Modules\ShahBot\Models\BotPayment::query()->where('status', 'pending')->count();
    $openTickets = \Modules\ShahBot\Models\BotTicket::query()->where('status', 'open')->count();
    $pendingRefunds = \Modules\ShahBot\Models\BotRefundRequest::query()->where('status', 'pending')->count();
@endphp
<div class="sb-head">
    <div>
        <h1><i class="bx bxl-telegram"></i> {{ __('shahbot::admin.title') }}</h1>
        <p>{{ __('shahbot::admin.subtitle') }}</p>
    </div>
</div>
{{-- A grid of grouped tiles that wraps onto as many rows as it needs. It
     used to be one strip that scrolled sideways, so on a laptop half the
     sections sat off screen and on a phone the strip slid left and right. --}}
@php
    $groups = [
        'nav_group_sales' => ['tab_dashboard', 'tab_users', 'tab_payments', 'tab_orders', 'tab_refunds', 'tab_codes'],
        'nav_group_resellers' => ['tab_agents', 'bot_access'],
        'nav_group_engage' => ['tab_fun', 'tab_broadcasts', 'tab_tickets', 'tab_tutorials'],
        'nav_group_setup' => ['tab_editor', 'tab_settings'],
    ];
    $byLabel = collect($tabs)->keyBy(fn ($t) => $t[2]);
@endphp
<nav class="sb-nav">
    @foreach ($groups as $groupLabel => $labels)
        <section class="sb-nav-group">
            <h2>{{ __('shahbot::admin.'.$groupLabel) }}</h2>
            <div class="sb-tabs">
                @foreach ($labels as $label)
                    @php [$route, $icon, , $patterns] = $byLabel[$label]; @endphp
                    <a href="{{ route($route) }}" @class(['sb-tab', 'is-active' => request()->routeIs(...$patterns)])>
                        <i class="bx {{ $icon }}"></i>
                        <span>{{ __('shahbot::admin.'.$label) }}</span>
                        @if ($route === 'admin.shahbot.payments.index' && $pendingCount > 0)<span class="sb-badge">{{ persian_digits($pendingCount) }}</span>@endif
                        @if ($route === 'admin.shahbot.refunds.index' && $pendingRefunds > 0)<span class="sb-badge">{{ persian_digits($pendingRefunds) }}</span>@endif
                        @if ($route === 'admin.shahbot.tickets.index' && $openTickets > 0)<span class="sb-badge">{{ persian_digits($openTickets) }}</span>@endif
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach
</nav>

@once
@push('styles')
<style>
    .sb-head { display:flex; justify-content:space-between; align-items:flex-end; gap:1rem; margin-bottom:1rem; }
    .sb-head h1 { font-size:1.35rem; font-weight:800; margin:0 0 .25rem; display:flex; align-items:center; gap:.5rem; }
    .sb-head h1 i { color:#229ED9; font-size:1.7rem; }
    .sb-head p { margin:0; color:var(--bs-secondary-color, #6b7280); font-size:.9rem; }
    .sb-nav { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:.9rem; margin-bottom:1.2rem; padding-bottom:1rem; border-bottom:1px solid rgba(127,127,127,.18); }
    .sb-nav-group h2 { font-size:.72rem; font-weight:700; letter-spacing:.02em; text-transform:uppercase; color:var(--bs-secondary-color, #6b7280); margin:0 0 .45rem; }
    .sb-tabs { display:grid; grid-template-columns:repeat(auto-fill, minmax(118px, 1fr)); gap:.4rem; }
    .sb-tab { position:relative; display:flex; align-items:center; gap:.45rem; min-width:0; padding:.55rem .65rem; border:1px solid rgba(127,127,127,.18); border-radius:.65rem; background:var(--bs-body-bg, #fff); color:inherit; text-decoration:none; font-size:.84rem; transition:background .15s, border-color .15s; }
    .sb-tab span:not(.sb-badge) { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sb-tab i { font-size:1.15rem; color:#229ED9; flex:none; }
    .sb-tab:hover { border-color:#229ED9; background:rgba(34,158,217,.06); }
    .sb-tab.is-active { background:#229ED9; border-color:#229ED9; color:#fff; }
    .sb-tab.is-active i { color:#fff; }
    .sb-tab .sb-badge { margin-inline-start:auto; flex:none; }
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
    @media (max-width: 576px) { .sb-head { flex-direction:column; align-items:flex-start; } .sb-nav { grid-template-columns:1fr; } .sb-tabs { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
</style>
@endpush
@endonce
