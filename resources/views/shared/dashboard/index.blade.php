@extends('layouts.panel')

@section('page_title', __('menu.dashboard'))

@section('panel_content')
@if (($stats['panel'] ?? null) === 'admin')
    @include('admin.partials.star-prompt')
@endif
@php
    $panel = $stats['panel'];
    $charts = $stats['charts'];
    $user = auth()->user();
    $range = (int) ($stats['range_days'] ?? 30);
    $period = $stats['period'] ?? [];
    $insights = $stats['insights'] ?? [];
    $isStaffPanel = in_array($panel, ['agent', 'seller'], true);

    $categoryMeta = [
        'wireguard' => ['label' => __('menu.accounts_wireguard'), 'color' => '#10b981', 'icon' => 'bx-shield-quarter', 'value' => (int) ($stats['accounts_wireguard'] ?? 0)],
        'ppp' => ['label' => __('menu.accounts_ppp'), 'color' => '#3b82f6', 'icon' => 'bx-plug', 'value' => (int) ($stats['accounts_ppp'] ?? 0)],
        'v2ray' => ['label' => __('menu.accounts_v2ray'), 'color' => '#f59e0b', 'icon' => 'bx-rocket', 'value' => (int) ($stats['accounts_v2ray'] ?? 0)],
        'anyconnect' => ['label' => __('menu.accounts_anyconnect'), 'color' => '#8b5cf6', 'icon' => 'bx-network-chart', 'value' => (int) ($stats['accounts_anyconnect'] ?? 0)],
    ];
    $categoryTotal = max(1, array_sum(array_column($categoryMeta, 'value')));

    $statusColors = [
        'active' => '#22c55e',
        'pending' => '#3b82f6',
        'disabled' => '#94a3b8',
        'exhausted' => '#f97316',
        'expired' => '#ef4444',
    ];

    $subtitle = match ($panel) {
        'admin' => __('dashboard.subtitle_admin'),
        'agent' => __('dashboard.subtitle_agent'),
        default => __('dashboard.subtitle_seller'),
    };

    $moneyTitle = match ($panel) {
        'admin' => __('dashboard.kpi.revenue'),
        'agent' => __('dashboard.kpi.income'),
        default => __('dashboard.kpi.spending'),
    };

    $quickActions = collect(match ($panel) {
        'admin' => [
            ['route' => 'admin.accounts.create', 'icon' => 'bx-plus-circle', 'label' => __('dashboard.actions.new_account')],
            ['route' => 'admin.payment-requests.index', 'icon' => 'bx-time-five', 'label' => __('dashboard.actions.payments')],
            ['route' => 'admin.clients.index', 'icon' => 'bx-group', 'label' => __('dashboard.actions.clients')],
            ['route' => 'admin.servers.index', 'icon' => 'bx-server', 'label' => __('dashboard.actions.servers')],
            ['route' => 'admin.users.index', 'icon' => 'bx-user-pin', 'label' => __('dashboard.actions.agents')],
            ['route' => 'admin.reports.index', 'icon' => 'bx-bar-chart-alt-2', 'label' => __('dashboard.actions.reports')],
        ],
        'agent' => [
            ['modal' => 'staff_create_account', 'icon' => 'bx-plus-circle', 'label' => __('dashboard.actions.new_account')],
            ['route' => 'agent.payment-requests.index', 'icon' => 'bx-time-five', 'label' => __('dashboard.actions.payments')],
            ['route' => 'agent.clients.index', 'icon' => 'bx-group', 'label' => __('dashboard.actions.clients')],
            ['route' => 'agent.sellers.index', 'icon' => 'bx-store', 'label' => __('dashboard.actions.sellers')],
            ['route' => 'agent.accounting.index', 'icon' => 'bx-calculator', 'label' => __('dashboard.actions.accounting')],
        ],
        default => [
            ['modal' => 'staff_create_account', 'icon' => 'bx-plus-circle', 'label' => __('dashboard.actions.new_account')],
            ['route' => 'seller.clients.index', 'icon' => 'bx-group', 'label' => __('dashboard.actions.clients')],
            ['route' => 'seller.payment-requests.create', 'icon' => 'bx-wallet', 'label' => __('dashboard.actions.new_charge')],
            ['route' => 'seller.accounting.index', 'icon' => 'bx-calculator', 'label' => __('dashboard.actions.accounting')],
            ['route' => 'seller.transactions.index', 'icon' => 'bx-transfer', 'label' => __('menu.transactions')],
        ],
    })->filter(function (array $item) use ($isStaffPanel): bool {
        if (($item['modal'] ?? null) === 'staff_create_account') {
            return $isStaffPanel;
        }

        // ادمینِ محدودشده نباید میان‌بری به بخشی ببیند که بازکردنش ۴۰۳ می‌دهد.
        return isset($item['route'])
            && \Illuminate\Support\Facades\Route::has($item['route'])
            && admin_route_allowed($item['route']);
    })->values();

    $paymentRoute = match ($panel) {
        'admin' => 'admin.payment-requests.index',
        'agent' => 'agent.payment-requests.index',
        default => 'seller.payment-requests.index',
    };

    $change = function (?float $value): array {
        if ($value === null) {
            return ['text' => __('dashboard.kpi.new'), 'tone' => 'flat', 'icon' => 'bx-star'];
        }

        return [
            'text' => persian_digits(($value > 0 ? '+' : '').rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').'%'),
            'tone' => $value > 0 ? 'up' : ($value < 0 ? 'down' : 'flat'),
            'icon' => $value > 0 ? 'bx-trending-up' : ($value < 0 ? 'bx-trending-down' : 'bx-minus'),
        ];
    };

    $activeRate = ($stats['accounts'] ?? 0) > 0 ? round(($stats['accounts_active'] / $stats['accounts']) * 100) : 0;
    $moneySum = collect($charts['money']['series'] ?? [])->first()['values'] ?? [];
    $statusTotal = array_sum($charts['status']['values'] ?? []);
@endphp

<div class="dash" dir="{{ locale_dir() }}">
    {{-- Header --}}
    <section class="dash-head">
        <div class="dash-head__text">
            <span class="dash-head__date"><i class="bx bx-calendar"></i> {{ jalali_date(now(), 'l j F Y') }}</span>
            <h1>{{ __('dashboard.welcome', ['name' => $user->full_name ?: $user->username]) }}</h1>
            <p>{{ $subtitle }}</p>
        </div>
        <div class="dash-head__side">
            <nav class="dash-range" aria-label="{{ __('dashboard.range_label') }}">
                @foreach (\App\Services\DashboardStatsService::RANGES as $days)
                    <a href="{{ request()->fullUrlWithQuery(['range' => $days]) }}" @class(['is-active' => $range === $days])>{{ __('dashboard.range_days', ['days' => persian_digits($days)]) }}</a>
                @endforeach
            </nav>
            @if (isset($stats['wallet_balance']))
                <div class="dash-wallet">
                    <span class="dash-wallet__icon"><i class="bx bx-wallet"></i></span>
                    <div>
                        <small>{{ __('wallet.remaining_balance') }}</small>
                        <strong>{{ ($stats['wallet_infinite'] ?? false) ? '∞' : format_money($stats['wallet_balance'], $stats['wallet_currency']) }}</strong>
                    </div>
                </div>
            @endif
        </div>
    </section>

    @if (($stats['pending_payments'] ?? 0) > 0 && \Illuminate\Support\Facades\Route::has($paymentRoute) && admin_route_allowed($paymentRoute))
        <div class="dash-alert">
            <i class="bx bx-error-circle"></i>
            <span>{{ __('dashboard.pending_alert', ['count' => persian_digits($stats['pending_payments'])]) }}</span>
            <a href="{{ route($paymentRoute) }}">{{ __('dashboard.review_payments') }} <i class="bx bx-left-arrow-alt"></i></a>
        </div>
    @endif

    @if ($isStaffPanel && ! empty($broadcastBanner['featured']))
        @include('shared.dashboard.partials.broadcast-banner', ['banner' => $broadcastBanner])
    @endif

    {{-- Quick actions --}}
    @if ($quickActions->isNotEmpty())
        <div class="dash-actions">
            @foreach ($quickActions as $action)
                @if (($action['modal'] ?? null) === 'staff_create_account')
                    <button type="button" class="dash-action dash-action--primary" data-staff-create-account-open><i class="bx {{ $action['icon'] }}"></i>{{ $action['label'] }}</button>
                @else
                    <a href="{{ route($action['route']) }}" class="dash-action"><i class="bx {{ $action['icon'] }}"></i>{{ $action['label'] }}</a>
                @endif
            @endforeach
        </div>
    @endif

    {{-- KPI cards --}}
    <section class="dash-kpis">
        @php $c = $change($period['new_accounts_change'] ?? null); @endphp
        <article class="dash-kpi">
            <header><span class="dash-kpi__icon" style="--tone:#6366f1"><i class="bx bx-user-plus"></i></span>{{ __('dashboard.kpi.new_accounts') }}</header>
            <div class="dash-kpi__value">{{ persian_digits(number_format($period['new_accounts'] ?? 0)) }}</div>
            <footer><span class="dash-delta dash-delta--{{ $c['tone'] }}"><i class="bx {{ $c['icon'] }}"></i>{{ $c['text'] }}</span> {{ __('dashboard.kpi.vs_previous', ['days' => persian_digits($range)]) }}</footer>
            <canvas class="dash-spark" data-spark='@json($charts['trend']['values'] ?? [])' data-color="#6366f1"></canvas>
        </article>

        @if (($period['money'] ?? null) !== null)
            @php $c = $change($period['money_change'] ?? null); @endphp
            <article class="dash-kpi">
                <header><span class="dash-kpi__icon" style="--tone:#10b981"><i class="bx bx-line-chart"></i></span>{{ $moneyTitle }}</header>
                <div class="dash-kpi__value">{{ format_money($period['money'], $period['money_currency'] ?? null) }}</div>
                <footer><span class="dash-delta dash-delta--{{ $panel === 'seller' ? 'flat' : $c['tone'] }}"><i class="bx {{ $c['icon'] }}"></i>{{ $c['text'] }}</span> {{ __('dashboard.kpi.vs_previous', ['days' => persian_digits($range)]) }}</footer>
                <canvas class="dash-spark" data-spark='@json($moneySum)' data-color="#10b981"></canvas>
            </article>
        @endif

        <article class="dash-kpi">
            <header><span class="dash-kpi__icon" style="--tone:#0ea5e9"><i class="bx bx-user-check"></i></span>{{ __('dashboard.kpi.active_accounts') }}</header>
            <div class="dash-kpi__value">{{ persian_digits(number_format($stats['accounts_active'] ?? 0)) }} <small>/ {{ persian_digits(number_format($stats['accounts'] ?? 0)) }}</small></div>
            <div class="dash-meter"><span style="width: {{ $activeRate }}%"></span></div>
            <footer>{{ __('dashboard.kpi.active_share', ['percent' => persian_digits($activeRate)]) }}</footer>
        </article>

        @if ($panel === 'admin')
            <article class="dash-kpi">
                <header><span class="dash-kpi__icon" style="--tone:#f59e0b"><i class="bx bx-server"></i></span>{{ __('menu.servers') }}</header>
                <div class="dash-kpi__value">{{ persian_digits($stats['active_servers'] ?? 0) }} <small>/ {{ persian_digits($stats['servers'] ?? 0) }}</small></div>
                <footer>{{ __('dashboard.servers_online', ['count' => persian_digits($stats['active_servers'] ?? 0)]) }} · {{ __('menu.agents') }}: {{ persian_digits($stats['agents'] ?? 0) }}</footer>
            </article>
        @elseif ($panel === 'agent')
            <article class="dash-kpi">
                <header><span class="dash-kpi__icon" style="--tone:#f59e0b"><i class="bx bx-store"></i></span>{{ __('menu.sellers') }}</header>
                <div class="dash-kpi__value">{{ persian_digits($stats['sellers'] ?? 0) }}</div>
                <footer>{{ __('dashboard.kpi.sellers_hint') }}</footer>
            </article>
        @else
            <article class="dash-kpi">
                <header><span class="dash-kpi__icon" style="--tone:#f59e0b"><i class="bx bx-transfer"></i></span>{{ __('menu.transactions') }}</header>
                <div class="dash-kpi__value">{{ persian_digits(number_format($stats['transactions'] ?? 0)) }}</div>
                <footer>{{ __('dashboard.kpi.transactions_hint') }}</footer>
            </article>
        @endif
    </section>

    {{-- Insight tiles --}}
    <section class="dash-tiles">
        <div class="dash-tile"><i class="bx bx-calendar-exclamation" style="--tone:#ef4444"></i><div><strong>{{ persian_digits($insights['expiring_7d'] ?? 0) }}</strong><span>{{ __('dashboard.insights.expiring_7d') }}</span></div></div>
        <div class="dash-tile"><i class="bx bx-battery" style="--tone:#f97316"></i><div><strong>{{ persian_digits($insights['near_quota'] ?? 0) }}</strong><span>{{ __('dashboard.insights.near_quota') }}</span></div></div>
        <div class="dash-tile"><i class="bx bx-data" style="--tone:#0ea5e9"></i><div><strong dir="ltr">{{ persian_digits(format_data_size((int) ($insights['used_bytes'] ?? 0))) }}</strong><span>{{ __('dashboard.insights.used') }}</span></div></div>
        <div class="dash-tile"><i class="bx bx-sun" style="--tone:#6366f1"></i><div><strong>{{ persian_digits($insights['new_today'] ?? 0) }}</strong><span>{{ __('dashboard.insights.new_today') }}</span></div></div>
    </section>

    {{-- Accounts over time + status --}}
    <section class="dash-grid dash-grid--8-4">
        <article class="dash-card">
            <header class="dash-card__head">
                <div><h3>{{ __('dashboard.charts.created') }}</h3><p>{{ __('dashboard.charts.created_hint', ['days' => persian_digits($range)]) }}</p></div>
            </header>
            <div class="dash-chart dash-chart--lg"><canvas id="chart-trend"></canvas></div>
        </article>
        <article class="dash-card">
            <header class="dash-card__head"><div><h3>{{ __('accounts.status_chart') }}</h3><p>{{ __('dashboard.charts.status_hint') }}</p></div></header>
            @if ($statusTotal > 0)
                <div class="dash-donut">
                    <canvas id="chart-status"></canvas>
                    <div class="dash-donut__center"><strong>{{ persian_digits(number_format($statusTotal)) }}</strong><span>{{ __('menu.accounts') }}</span></div>
                </div>
                <ul class="dash-legend">
                    @foreach ($charts['status']['labels'] as $i => $label)
                        @php $key = $charts['status_keys'][$i] ?? 'active'; $val = $charts['status']['values'][$i]; @endphp
                        <li><i style="background: {{ $statusColors[$key] ?? '#94a3b8' }}"></i><span>{{ $label }}</span><b>{{ persian_digits(number_format($val)) }}</b><em>{{ persian_digits(round($val / $statusTotal * 100)) }}%</em></li>
                    @endforeach
                </ul>
            @else
                <div class="dash-empty"><i class="bx bx-pie-chart-alt"></i>{{ __('dashboard.no_chart_data') }}</div>
            @endif
        </article>
    </section>

    {{-- Money + service mix --}}
    <section class="dash-grid dash-grid--8-4">
        <article class="dash-card">
            <header class="dash-card__head"><div><h3>{{ __('dashboard.charts.money_'.$panel) }}</h3><p>{{ __('dashboard.charts.money_hint', ['days' => persian_digits($range)]) }}</p></div></header>
            <div class="dash-chart dash-chart--lg"><canvas id="chart-money"></canvas></div>
        </article>
        <article class="dash-card">
            <header class="dash-card__head"><div><h3>{{ __('dashboard.service_breakdown') }}</h3><p>{{ __('dashboard.charts.mix_hint') }}</p></div></header>
            <ul class="dash-mix">
                @foreach ($categoryMeta as $key => $meta)
                    @php $pct = round($meta['value'] / $categoryTotal * 100); @endphp
                    <li>
                        <div class="dash-mix__row">
                            <span class="dash-mix__icon" style="--tone: {{ $meta['color'] }}"><i class="bx {{ $meta['icon'] }}"></i></span>
                            <span class="dash-mix__name">{{ $meta['label'] }}</span>
                            <b>{{ persian_digits(number_format($meta['value'])) }}</b>
                        </div>
                        <div class="dash-meter dash-meter--thin"><span style="width: {{ $pct }}%; background: {{ $meta['color'] }}"></span></div>
                        <small>{{ persian_digits($pct) }}%</small>
                    </li>
                @endforeach
            </ul>
        </article>
    </section>

    {{-- Servers + packages --}}
    <section class="dash-grid dash-grid--6-6">
        <article class="dash-card">
            <header class="dash-card__head"><div><h3>{{ __('dashboard.charts.servers') }}</h3><p>{{ __('dashboard.charts.servers_hint') }}</p></div></header>
            <div class="dash-chart"><canvas id="chart-servers"></canvas></div>
        </article>
        <article class="dash-card">
            <header class="dash-card__head"><div><h3>{{ __('dashboard.charts.packages') }}</h3><p>{{ __('dashboard.charts.packages_hint') }}</p></div></header>
            <div class="dash-chart"><canvas id="chart-packages"></canvas></div>
        </article>
    </section>

    {{-- Usage + expiring --}}
    <section class="dash-grid dash-grid--6-6">
        <article class="dash-card">
            <header class="dash-card__head"><div><h3>{{ __('dashboard.charts.usage') }}</h3><p>{{ __('dashboard.charts.usage_hint') }}</p></div></header>
            <div class="dash-chart"><canvas id="chart-usage"></canvas></div>
        </article>
        <article class="dash-card">
            <header class="dash-card__head"><div><h3>{{ __('dashboard.charts.expiring') }}</h3><p>{{ __('dashboard.charts.expiring_hint') }}</p></div></header>
            <div class="dash-chart"><canvas id="chart-expiring"></canvas></div>
        </article>
    </section>

    @if ($panel === 'admin' && ! empty($stats['top_agents']))
        @php $maxAgent = max(1, max(array_column($stats['top_agents'], 'accounts'))); @endphp
        <section class="dash-card">
            <header class="dash-card__head"><div><h3>{{ __('dashboard.charts.top_agents') }}</h3><p>{{ __('dashboard.charts.top_agents_hint', ['days' => persian_digits($range)]) }}</p></div></header>
            <ol class="dash-rank">
                @foreach ($stats['top_agents'] as $i => $agentRow)
                    <li>
                        <span class="dash-rank__pos">{{ persian_digits($i + 1) }}</span>
                        <span class="dash-rank__name">{{ $agentRow['name'] }}</span>
                        <div class="dash-meter dash-meter--thin"><span style="width: {{ round($agentRow['accounts'] / $maxAgent * 100) }}%"></span></div>
                        <b>{{ persian_digits(number_format($agentRow['accounts'])) }}</b>
                        <small>{{ __('dashboard.charts.active_count', ['count' => persian_digits($agentRow['active'])]) }}</small>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    @if ($panel === 'admin')
        <h2 class="dash-section">{{ __('dashboard.system_section') }}</h2>
        @include('shared.dashboard.partials.server-monitor')
        <details class="dash-details">
            <summary><i class="bx bx-pulse"></i> {{ __('dashboard.health.title') }}</summary>
            @include('shared.dashboard.partials.system-health-monitor', ['initialHealth' => $systemHealth ?? null])
        </details>
    @endif
</div>

<div class="modal admin-account-report-modal" id="dashboard-trend-day-modal" tabindex="-1" role="dialog" aria-labelledby="dashboard-trend-day-title" hidden>
    <div class="modal-dialog admin-account-report-modal__dialog" role="document">
        <div class="modal-content admin-account-report-modal__content">
            <div class="modal-header">
                <h5 class="modal-title" id="dashboard-trend-day-title">{{ __('accounts.trend_day_details_title', ['date' => '']) }}</h5>
                <button type="button" class="btn-close dashboard-trend-day-modal__close" aria-label="{{ __('app.cancel') }}"></button>
            </div>
            <div class="modal-body" id="dashboard-trend-day-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary dashboard-trend-day-modal__close">{{ __('app.cancel') }}</button>
            </div>
        </div>
    </div>
</div>

@if ($isStaffPanel)
    @include('shared.accounts.create-modal', [
        'prefix' => $prefix ?? $panel,
        'accountOwners' => $accountOwners ?? collect(),
        'openCreateModal' => $openCreateModal ?? false,
    ])
@endif
@endsection

@push('styles')
<style>
    .dash { display: flex; flex-direction: column; gap: 18px; font-feature-settings: "ss01"; }
    .dash h1, .dash h2, .dash h3 { margin: 0; }
    .dash-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; padding: 22px 24px; border-radius: 20px; color: #fff;
        background: radial-gradient(1200px 300px at 0% 0%, rgba(255,255,255,.18), transparent 60%), linear-gradient(135deg, var(--hero-from, #6366f1), var(--hero-mid, #4338ca) 55%, var(--hero-to, #312e81)); box-shadow: 0 18px 40px -22px rgba(49,46,129,.7); }
    .dash-head h1 { font-size: 1.45rem; font-weight: 800; margin: 6px 0 4px; }
    .dash-head p { margin: 0; opacity: .85; font-size: .92rem; }
    .dash-head__date { display: inline-flex; align-items: center; gap: 6px; font-size: .78rem; background: rgba(255,255,255,.16); padding: 4px 10px; border-radius: 999px; }
    .dash-head__side { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
    .dash-range { display: inline-flex; background: rgba(15,23,42,.25); border-radius: 12px; padding: 4px; gap: 2px; }
    .dash-range a { color: rgba(255,255,255,.85); text-decoration: none; font-size: .82rem; padding: 6px 12px; border-radius: 9px; transition: background .15s; }
    .dash-range a:hover { background: rgba(255,255,255,.12); color: #fff; }
    .dash-range a.is-active { background: #fff; color: var(--accent, #4f46e5); font-weight: 700; }
    .dash-wallet { display: flex; align-items: center; gap: 10px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.2); padding: 8px 14px; border-radius: 14px; }
    .dash-wallet__icon { width: 36px; height: 36px; display: grid; place-items: center; border-radius: 10px; background: rgba(255,255,255,.2); font-size: 1.2rem; }
    .dash-wallet small { display: block; font-size: .72rem; opacity: .8; }
    .dash-wallet strong { font-size: 1.05rem; }

    .dash-alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 14px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; font-size: .9rem; }
    .dash-alert i { font-size: 1.25rem; }
    .dash-alert a { margin-inline-start: auto; font-weight: 700; color: #b45309; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
    [dir="ltr"] .dash-alert a i { transform: scaleX(-1); }

    .dash-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .dash-action { display: inline-flex; align-items: center; gap: 7px; padding: 9px 14px; border-radius: 12px; background: var(--surface, #fff); border: 1px solid var(--border, #e6e9ef); color: var(--text-soft, #334155); font-size: .86rem; font-weight: 600; text-decoration: none; cursor: pointer; transition: border-color .15s, transform .15s, box-shadow .15s; }
    .dash-action i { font-size: 1.1rem; color: var(--accent, #4f46e5); }
    .dash-action:hover { border-color: var(--accent, #4f46e5); transform: translateY(-1px); box-shadow: 0 6px 16px -10px rgba(79,70,229,.6); color: var(--text, #0f172a); }
    .dash-action--primary { background: var(--accent, #4f46e5); color: #fff; border-color: transparent; }
    .dash-action--primary i { color: #fff; }

    .dash-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
    .dash-kpi { position: relative; overflow: hidden; background: var(--surface, #fff); border: 1px solid var(--border, #e6e9ef); border-radius: 18px; padding: 16px 18px 50px; display: flex; flex-direction: column; gap: 8px; min-height: 150px; }
    .dash-kpi header { display: flex; align-items: center; gap: 10px; font-size: .85rem; color: var(--muted, #64748b); font-weight: 600; }
    .dash-kpi__icon { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 10px; font-size: 1.15rem; color: var(--tone); background: color-mix(in srgb, var(--tone) 13%, transparent); }
    .dash-kpi__value { font-size: 1.55rem; font-weight: 800; color: var(--text, #0f172a); line-height: 1.2; position: relative; z-index: 1; }
    .dash-kpi__value small { font-size: .85rem; color: var(--muted-2, #94a3b8); font-weight: 600; }
    .dash-kpi footer { font-size: .76rem; color: var(--muted, #64748b); position: relative; z-index: 1; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .dash-spark { position: absolute; inset-inline: 0; bottom: 0; width: 100% !important; height: 40px !important; opacity: .85; pointer-events: none; }
    .dash-delta { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 999px; font-weight: 700; direction: ltr; }
    .dash-delta--up { background: #dcfce7; color: #15803d; }
    .dash-delta--down { background: #fee2e2; color: #b91c1c; }
    .dash-delta--flat { background: #f1f5f9; color: #475569; }
    .dash-meter { height: 8px; border-radius: 99px; background: #eef2f7; overflow: hidden; }
    .dash-meter span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #0ea5e9, #6366f1); transition: width .6s ease; }
    .dash-meter--thin { height: 6px; }

    .dash-tiles { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
    .dash-tile { display: flex; align-items: center; gap: 12px; padding: 12px 14px; background: var(--surface, #fff); border: 1px solid var(--border, #e6e9ef); border-radius: 16px; }
    .dash-tile > i { width: 40px; height: 40px; flex: none; display: grid; place-items: center; border-radius: 12px; font-size: 1.25rem; color: var(--tone); background: color-mix(in srgb, var(--tone) 12%, transparent); }
    .dash-tile strong { display: block; font-size: 1.15rem; color: var(--text, #0f172a); }
    .dash-tile span { font-size: .78rem; color: var(--muted, #64748b); }

    .dash-grid { display: grid; gap: 14px; }
    .dash-grid--8-4 { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); }
    .dash-grid--6-6 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .dash-card { background: var(--surface, #fff); border: 1px solid var(--border, #e6e9ef); border-radius: 18px; padding: 16px 18px; min-width: 0; }
    .dash-card__head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 12px; }
    .dash-card__head h3 { font-size: 1rem; font-weight: 800; color: var(--text, #0f172a); }
    .dash-card__head p { margin: 3px 0 0; font-size: .78rem; color: var(--muted, #64748b); }
    .dash-chart { position: relative; height: 260px; }
    .dash-chart--lg { height: 300px; }
    .dash-empty { display: grid; place-items: center; gap: 6px; padding: 40px 0; color: var(--muted-2, #94a3b8); font-size: .85rem; }
    .dash-empty i { font-size: 2rem; }

    .dash-donut { position: relative; height: 190px; }
    .dash-donut__center { position: absolute; inset: 0; display: grid; place-content: center; text-align: center; pointer-events: none; }
    .dash-donut__center strong { font-size: 1.4rem; font-weight: 800; color: var(--text, #0f172a); }
    .dash-donut__center span { font-size: .75rem; color: var(--muted, #64748b); }
    .dash-legend { list-style: none; margin: 14px 0 0; padding: 0; display: grid; gap: 8px; }
    .dash-legend li { display: grid; grid-template-columns: 12px 1fr auto 44px; align-items: center; gap: 8px; font-size: .84rem; }
    .dash-legend i { width: 10px; height: 10px; border-radius: 3px; }
    .dash-legend b { color: var(--text, #0f172a); }
    .dash-legend em { font-style: normal; color: var(--muted, #64748b); text-align: end; font-size: .78rem; }

    .dash-mix { list-style: none; margin: 0; padding: 0; display: grid; gap: 14px; }
    .dash-mix__row { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; font-size: .86rem; }
    .dash-mix__icon { width: 30px; height: 30px; display: grid; place-items: center; border-radius: 9px; color: var(--tone); background: color-mix(in srgb, var(--tone) 13%, transparent); }
    .dash-mix__name { flex: 1; color: var(--text-soft, #334155); font-weight: 600; }
    .dash-mix li small { font-size: .72rem; color: var(--muted, #64748b); }

    .dash-rank { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
    .dash-rank li { display: grid; grid-template-columns: 28px minmax(120px, 1.2fr) 3fr 60px 90px; align-items: center; gap: 12px; font-size: .86rem; }
    .dash-rank__pos { width: 26px; height: 26px; display: grid; place-items: center; border-radius: 8px; background: var(--accent-soft, #eef2ff); color: var(--accent, #4f46e5); font-weight: 800; font-size: .8rem; }
    .dash-rank__name { font-weight: 600; color: var(--text, #0f172a); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dash-rank b { text-align: end; }
    .dash-rank small { color: var(--muted, #64748b); }

    .dash-section { font-size: 1rem; font-weight: 800; color: var(--text, #0f172a); margin-top: 6px; }
    .dash-details { background: var(--surface, #fff); border: 1px solid var(--border, #e6e9ef); border-radius: 18px; padding: 4px 16px; }
    .dash-details > summary { cursor: pointer; padding: 12px 0; font-weight: 700; color: var(--text-soft, #334155); list-style: none; display: flex; align-items: center; gap: 8px; }
    .dash-details > summary::-webkit-details-marker { display: none; }
    .dash-details > summary::after { content: '\25BE'; margin-inline-start: auto; transition: transform .2s; }
    .dash-details[open] > summary::after { transform: rotate(180deg); }

    @media (max-width: 1199px) {
        .dash-kpis, .dash-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .dash-grid--8-4 { grid-template-columns: 1fr; }
    }
    @media (max-width: 767px) {
        .dash-kpis, .dash-grid--6-6 { grid-template-columns: 1fr; }
        .dash-tile { flex-direction: column; align-items: flex-start; gap: 8px; }
        .dash-head { padding: 18px; }
        .dash-rank li { grid-template-columns: 26px 1fr 50px; }
        .dash-rank li .dash-meter, .dash-rank li small { display: none; }
    }
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    var rtl = @json(locale_is_rtl());
    var numberFormat = new Intl.NumberFormat(@json(locale_tag()));
    var compactFormat = new Intl.NumberFormat(@json(locale_tag()), { notation: 'compact', maximumFractionDigits: 1 });
    var fmt = function (v) { return numberFormat.format(Number(v || 0)); };
    var fmtCompact = function (v) { return compactFormat.format(Number(v || 0)); };

    Chart.defaults.font.family = 'Vazirmatn, Tahoma, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#64748b';
    Chart.defaults.borderColor = 'rgba(148, 163, 184, .18)';
    Chart.defaults.plugins.tooltip.rtl = rtl;
    Chart.defaults.plugins.tooltip.textDirection = rtl ? 'rtl' : 'ltr';
    Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 10;
    Chart.defaults.plugins.tooltip.boxPadding = 4;
    Chart.defaults.plugins.legend.rtl = rtl;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.pointStyle = 'rectRounded';
    Chart.defaults.maintainAspectRatio = false;

    var linearY = { beginAtZero: true, position: rtl ? 'right' : 'left', ticks: { precision: 0, callback: function (v) { return fmtCompact(v); } }, grid: { drawBorder: false } };
    var categoryX = { grid: { display: false }, reverse: rtl, ticks: { maxRotation: 0, autoSkipPadding: 12 } };

    function gradient(ctx, color) {
        var g = ctx.createLinearGradient(0, 0, 0, ctx.canvas.clientHeight || 260);
        g.addColorStop(0, color + '55');
        g.addColorStop(1, color + '00');
        return g;
    }

    // Sparklines inside the KPI cards.
    document.querySelectorAll('canvas.dash-spark').forEach(function (el) {
        var data = [];
        try { data = JSON.parse(el.getAttribute('data-spark') || '[]'); } catch (e) {}
        if (!data.length || !data.some(function (v) { return Number(v) > 0; })) return;
        var color = el.getAttribute('data-color') || '#6366f1';
        new Chart(el, {
            type: 'line',
            data: { labels: data.map(function (_, i) { return i; }), datasets: [{ data: data, borderColor: color, borderWidth: 2, pointRadius: 0, tension: .4, fill: true, backgroundColor: gradient(el.getContext('2d'), color) }] },
            options: { animation: false, plugins: { legend: { display: false }, tooltip: { enabled: false } }, scales: { x: { display: false, reverse: rtl }, y: { display: false, beginAtZero: true } } }
        });
    });

    var charts = @json($charts);
    var categoryMeta = @json(collect($categoryMeta)->map(fn ($m) => ['label' => $m['label'], 'color' => $m['color']]));
    var statusColors = @json($statusColors);
    var emptyText = @json(__('dashboard.no_chart_data'));

    function emptyState(canvas) {
        var box = canvas.parentElement;
        box.innerHTML = '<div class="dash-empty"><i class="bx bx-bar-chart-alt-2"></i>' + emptyText + '</div>';
    }

    // 1) Accounts created per day, stacked by service family; click a day for detail.
    var trend = charts.trend || {};
    var trendEl = document.getElementById('chart-trend');
    if (trendEl) {
        var rows = trend.by_category || [];
        if (!(trend.values || []).some(function (v) { return v > 0; })) {
            emptyState(trendEl);
        } else {
            new Chart(trendEl, {
                type: 'bar',
                data: {
                    labels: trend.labels,
                    datasets: Object.keys(categoryMeta).map(function (key) {
                        return { label: categoryMeta[key].label, data: rows.map(function (r) { return Number(r[key] || 0); }), backgroundColor: categoryMeta[key].color, borderRadius: 4, borderSkipped: false, maxBarThickness: 26, stack: 'accounts' };
                    })
                },
                options: {
                    interaction: { mode: 'index', intersect: false },
                    onClick: function (evt, elements) { if (elements && elements.length) openTrendDayModal(elements[0].index); },
                    onHover: function (evt, elements) { evt.native.target.style.cursor = elements.length ? 'pointer' : 'default'; },
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            filter: function (item) { return item.parsed.y > 0; },
                            callbacks: {
                                label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + fmt(ctx.parsed.y); },
                                footer: function (items) { var t = 0; items.forEach(function (i) { t += i.parsed.y; }); return @json(__('dashboard.charts.total')) + ': ' + fmt(t); }
                            }
                        }
                    },
                    scales: { x: Object.assign({ stacked: true }, categoryX), y: Object.assign({ stacked: true }, linearY) }
                }
            });
        }
    }

    // 2) Status doughnut.
    var statusEl = document.getElementById('chart-status');
    if (statusEl) {
        var status = charts.status || {};
        new Chart(statusEl, {
            type: 'doughnut',
            data: { labels: status.labels, datasets: [{ data: status.values, backgroundColor: (charts.status_keys || []).map(function (k) { return statusColors[k] || '#94a3b8'; }), borderWidth: 3, borderColor: '#fff', hoverOffset: 6 }] },
            options: {
                cutout: '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) {
                        var row = (status.by_category || [])[ctx.dataIndex] || {};
                        var lines = [' ' + ctx.label + ': ' + fmt(ctx.parsed)];
                        Object.keys(categoryMeta).forEach(function (k) { if (row[k]) lines.push('   ' + categoryMeta[k].label + ': ' + fmt(row[k])); });
                        return lines;
                    } } }
                }
            }
        });
    }

    // 3) Money over time.
    var money = charts.money || {};
    var moneyEl = document.getElementById('chart-money');
    if (moneyEl) {
        var palette = ['#10b981', '#6366f1'];
        var hasMoney = (money.series || []).some(function (s) { return s.values.some(function (v) { return v > 0; }); });
        if (!hasMoney) {
            emptyState(moneyEl);
        } else {
            var mctx = moneyEl.getContext('2d');
            new Chart(moneyEl, {
                type: 'line',
                data: {
                    labels: money.labels,
                    datasets: money.series.map(function (s, i) {
                        return { label: s.label, data: s.values, borderColor: palette[i % 2], backgroundColor: gradient(mctx, palette[i % 2]), fill: i === 0, borderWidth: 2.5, tension: .35, pointRadius: 0, pointHoverRadius: 5, pointBackgroundColor: palette[i % 2] };
                    })
                },
                options: {
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + fmt(ctx.parsed.y); } } } },
                    scales: { x: categoryX, y: linearY }
                }
            });
        }
    }

    // 4/5) Top servers and packages: horizontal, active vs. the rest.
    function horizontal(id, rows) {
        var el = document.getElementById(id);
        if (!el) return;
        if (!rows || !rows.length) { emptyState(el); return; }
        new Chart(el, {
            type: 'bar',
            data: {
                labels: rows.map(function (r) { return r.name; }),
                datasets: [
                    { label: @json(__('accounts.status_active')), data: rows.map(function (r) { return r.active; }), backgroundColor: '#22c55e', borderRadius: 6, stack: 's', maxBarThickness: 18 },
                    { label: @json(__('dashboard.charts.other_status')), data: rows.map(function (r) { return r.other; }), backgroundColor: '#cbd5e1', borderRadius: 6, stack: 's', maxBarThickness: 18 }
                ]
            },
            options: {
                indexAxis: 'y',
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: {
                    label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + fmt(ctx.parsed.x); },
                    footer: function (items) { var r = rows[items[0].dataIndex]; return @json(__('dashboard.charts.total')) + ': ' + fmt(r.total); }
                } } },
                scales: {
                    x: { stacked: true, beginAtZero: true, reverse: rtl, ticks: { precision: 0, callback: function (v) { return fmtCompact(v); } } },
                    y: { stacked: true, grid: { display: false }, position: rtl ? 'right' : 'left', ticks: { callback: function (v) { var l = this.getLabelForValue(v); return l.length > 22 ? l.slice(0, 21) + '…' : l; } } }
                }
            }
        });
    }
    horizontal('chart-servers', charts.servers);
    horizontal('chart-packages', charts.packages);

    // 6) Data usage of active accounts.
    var usage = charts.usage || {};
    var usageEl = document.getElementById('chart-usage');
    if (usageEl) {
        if (!(usage.values || []).some(function (v) { return v > 0; })) { emptyState(usageEl); }
        else new Chart(usageEl, {
            type: 'bar',
            data: { labels: usage.labels, datasets: [{ label: @json(__('menu.accounts')), data: usage.values, backgroundColor: ['#22c55e', '#84cc16', '#eab308', '#f97316', '#ef4444', '#8b5cf6'], borderRadius: 8, maxBarThickness: 46 }] },
            options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return ' ' + fmt(ctx.parsed.y); } } } }, scales: { x: categoryX, y: linearY } }
        });
    }

    // 7) Expiring in the next 14 days.
    var expiring = charts.expiring || {};
    var expEl = document.getElementById('chart-expiring');
    if (expEl) {
        if (!(expiring.values || []).some(function (v) { return v > 0; })) { emptyState(expEl); }
        else new Chart(expEl, {
            type: 'bar',
            data: { labels: expiring.labels, datasets: [{ label: @json(__('dashboard.charts.expiring')), data: expiring.values, backgroundColor: expiring.values.map(function (_, i) { return i < 3 ? '#ef4444' : (i < 7 ? '#f59e0b' : '#38bdf8'); }), borderRadius: 6, maxBarThickness: 28 }] },
            options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return ' ' + fmt(ctx.parsed.y); } } } }, scales: { x: categoryX, y: linearY } }
        });
    }

    // Day detail modal for the creation chart.
    var trendDetails = trend.details || [];
    var trendModal = document.getElementById('dashboard-trend-day-modal');
    var trendModalTitle = document.getElementById('dashboard-trend-day-title');
    var trendModalBody = document.getElementById('dashboard-trend-day-body');
    var trendTitleTpl = @json(__('accounts.trend_day_details_title', ['date' => ':date']));
    var trendTotalTpl = @json(__('accounts.trend_day_total', ['count' => ':count']));
    var trendEmptyLabel = @json(__('accounts.trend_day_empty'));
    var colServer = @json(__('accounts.trend_day_server'));
    var colPackage = @json(__('accounts.trend_day_package'));
    var colCount = @json(__('accounts.trend_day_count'));

    function escapeHtml(value) {
        return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function openTrendDayModal(index) {
        if (!trendModal || !trendModalBody || !trendDetails[index]) return;
        var day = trendDetails[index];
        trendModalTitle.textContent = trendTitleTpl.replace(':date', day.jalali || day.label || '');
        if (!day.total) {
            trendModalBody.innerHTML = '<p class="text-muted mb-0">' + escapeHtml(trendEmptyLabel) + '</p>';
        } else {
            var html = '<p class="mb-3"><strong>' + escapeHtml(trendTotalTpl.replace(':count', fmt(day.total))) + '</strong></p>';
            Object.keys(day.categories || {}).forEach(function (key) {
                var block = day.categories[key] || {};
                if (!block.total) return;
                html += '<div class="mb-3"><h6 class="mb-2">' + escapeHtml(block.label || key) + ' <span class="badge bg-secondary">' + fmt(block.total) + '</span></h6>';
                html += '<div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>' + escapeHtml(colServer) + '</th><th>' + escapeHtml(colPackage) + '</th><th class="text-center">' + escapeHtml(colCount) + '</th></tr></thead><tbody>';
                (block.items || []).forEach(function (item) {
                    html += '<tr><td>' + escapeHtml(item.server) + '</td><td>' + escapeHtml(item.package) + '</td><td class="text-center">' + fmt(item.count) + '</td></tr>';
                });
                html += '</tbody></table></div></div>';
            });
            trendModalBody.innerHTML = html;
        }
        trendModal.hidden = false;
        trendModal.classList.add('show');
        document.body.classList.add('admin-account-report-modal-open');
    }

    function closeTrendDayModal() {
        if (!trendModal) return;
        trendModal.hidden = true;
        trendModal.classList.remove('show');
        document.body.classList.remove('admin-account-report-modal-open');
    }

    if (trendModal) {
        trendModal.querySelectorAll('.dashboard-trend-day-modal__close').forEach(function (btn) { btn.addEventListener('click', closeTrendDayModal); });
        trendModal.addEventListener('click', function (e) { if (e.target === trendModal) closeTrendDayModal(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !trendModal.hidden) closeTrendDayModal(); });
    }
});
</script>
@endpush
