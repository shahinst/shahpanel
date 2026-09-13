@extends('layouts.panel')

@section('page_title', __('menu.dashboard'))

@section('panel_content')
@php
    $panel = $stats['panel'];
    $charts = $stats['charts'];
    $user = auth()->user();

    $trendLabels = $charts['trend']['labels'] ?? [];
    $trendValues = $charts['trend']['values'] ?? [];
    $trendByCategory = $charts['trend']['by_category'] ?? [];
    $trendDetails = $charts['trend']['details'] ?? [];
    $trendCategoryLabels = $charts['trend']['category_labels'] ?? [
        'wireguard' => __('menu.accounts_wireguard'),
        'ppp' => __('menu.accounts_ppp'),
        'v2ray' => __('menu.accounts_v2ray'),
        'anyconnect' => __('menu.accounts_anyconnect'),
    ];
    $statusLabels = $charts['status']['labels'] ?? [];
    $statusValues = $charts['status']['values'] ?? [];
    $statusByCategory = $charts['status']['by_category'] ?? [];
    $categoryLabels = $charts['category']['labels'] ?? [];
    $categoryValues = $charts['category']['values'] ?? [];

    $heroClass = match ($panel) {
        'admin' => 'panel-dash-hero panel-dash-hero--admin',
        'agent' => 'panel-dash-hero panel-dash-hero--agent',
        default => 'panel-dash-hero panel-dash-hero--seller',
    };

    $subtitle = match ($panel) {
        'admin' => __('dashboard.subtitle_admin'),
        'agent' => __('dashboard.subtitle_agent'),
        default => __('dashboard.subtitle_seller'),
    };

    $isStaffPanel = in_array($panel, ['agent', 'seller'], true);

    $quickActions = collect(match ($panel) {
        'admin' => [
            ['route' => 'admin.accounts.create', 'icon' => 'bx-plus-circle', 'label' => __('dashboard.actions.new_account'), 'tone' => 'primary'],
            ['route' => 'admin.payment-requests.index', 'icon' => 'bx-time-five', 'label' => __('dashboard.actions.payments'), 'tone' => 'warning'],
            ['route' => 'admin.clients.index', 'icon' => 'bx-group', 'label' => __('dashboard.actions.clients'), 'tone' => 'info'],
            ['route' => 'admin.servers.index', 'icon' => 'bx-server', 'label' => __('dashboard.actions.servers'), 'tone' => 'success'],
            ['route' => 'admin.users.index', 'icon' => 'bx-user-pin', 'label' => __('dashboard.actions.agents'), 'tone' => 'indigo'],
            ['route' => 'admin.reports.index', 'icon' => 'bx-bar-chart-alt-2', 'label' => __('dashboard.actions.reports'), 'tone' => 'slate'],
        ],
        'agent' => [
            ['modal' => 'staff_create_account', 'icon' => 'bx-plus-circle', 'label' => __('dashboard.actions.new_account'), 'tone' => 'primary'],
            ['route' => 'agent.payment-requests.index', 'icon' => 'bx-time-five', 'label' => __('dashboard.actions.payments'), 'tone' => 'warning'],
            ['route' => 'agent.clients.index', 'icon' => 'bx-group', 'label' => __('dashboard.actions.clients'), 'tone' => 'info'],
            ['route' => 'agent.sellers.index', 'icon' => 'bx-store', 'label' => __('dashboard.actions.sellers'), 'tone' => 'success'],
            ['route' => 'agent.accounting.index', 'icon' => 'bx-calculator', 'label' => __('dashboard.actions.accounting'), 'tone' => 'indigo'],
        ],
        default => [
            ['modal' => 'staff_create_account', 'icon' => 'bx-plus-circle', 'label' => __('dashboard.actions.new_account'), 'tone' => 'primary'],
            ['route' => 'seller.clients.index', 'icon' => 'bx-group', 'label' => __('dashboard.actions.clients'), 'tone' => 'info'],
            ['route' => 'seller.payment-requests.create', 'icon' => 'bx-wallet', 'label' => __('dashboard.actions.new_charge'), 'tone' => 'warning'],
            ['route' => 'seller.accounting.index', 'icon' => 'bx-calculator', 'label' => __('dashboard.actions.accounting'), 'tone' => 'indigo'],
            ['route' => 'seller.transactions.index', 'icon' => 'bx-transfer', 'label' => __('menu.transactions'), 'tone' => 'slate'],
        ],
    })->filter(function (array $item) use ($isStaffPanel): bool {
        if (($item['modal'] ?? null) === 'staff_create_account') {
            return $isStaffPanel;
        }

        return isset($item['route']) && \Illuminate\Support\Facades\Route::has($item['route']);
    })->values();

    $kpiCards = collect([
        isset($stats['wallet_balance']) ? [
            'title' => __('wallet.remaining_balance'),
            'value' => format_toman($stats['wallet_balance']),
            'hint' => ($stats['wallet_infinite'] ?? false) ? __('wallet.infinite_hint') : null,
            'icon' => 'bx-wallet',
            'tone' => 'emerald',
        ] : null,
        $panel === 'admin' && isset($stats['total_catalog_sales']) ? [
            'title' => __('dashboard.total_catalog_revenue'),
            'value' => format_toman($stats['total_catalog_sales']),
            'hint' => __('dashboard.total_catalog_revenue_hint'),
            'icon' => 'bx-purchase-tag-alt',
            'tone' => 'emerald',
        ] : null,
        $panel === 'admin' && isset($stats['total_agent_margin']) ? [
            'title' => __('dashboard.total_agent_revenue'),
            'value' => format_toman($stats['total_agent_margin']),
            'hint' => __('dashboard.total_agent_revenue_hint'),
            'icon' => 'bx-trending-up',
            'tone' => 'amber',
        ] : null,
        $panel === 'admin' ? [
            'title' => __('menu.agents'),
            'value' => persian_digits($stats['agents']),
            'hint' => null,
            'icon' => 'bx-user-pin',
            'tone' => 'violet',
        ] : null,
        $panel === 'admin' ? [
            'title' => __('menu.servers'),
            'value' => persian_digits($stats['servers']),
            'hint' => __('dashboard.servers_online', ['count' => persian_digits($stats['active_servers'])]),
            'icon' => 'bx-server',
            'tone' => 'sky',
        ] : null,
        $panel === 'agent' ? [
            'title' => __('menu.sellers'),
            'value' => persian_digits($stats['sellers']),
            'hint' => null,
            'icon' => 'bx-store',
            'tone' => 'violet',
        ] : null,
        isset($stats['transactions']) ? [
            'title' => __('menu.transactions'),
            'value' => persian_digits($stats['transactions']),
            'hint' => null,
            'icon' => 'bx-transfer',
            'tone' => 'blue',
        ] : null,
        [
            'title' => __('menu.accounts'),
            'value' => persian_digits($stats['accounts']),
            'hint' => __('dashboard.active_rate', [
                'active' => persian_digits($stats['accounts_active']),
                'total' => persian_digits($stats['accounts']),
            ]),
            'icon' => 'bx-user-circle',
            'tone' => 'primary',
        ],
        isset($stats['pending_payments']) ? [
            'title' => __('menu.pending_payments'),
            'value' => persian_digits($stats['pending_payments']),
            'hint' => null,
            'icon' => 'bx-time-five',
            'tone' => 'amber',
        ] : null,
    ])->filter()->values();

    $serviceTypes = [
        ['label' => __('menu.accounts_wireguard'), 'value' => $stats['accounts_wireguard'], 'icon' => 'bx-shield-quarter', 'tone' => 'green'],
        ['label' => __('menu.accounts_ppp'), 'value' => $stats['accounts_ppp'], 'icon' => 'bx-plug', 'tone' => 'blue'],
        ['label' => __('menu.accounts_v2ray'), 'value' => $stats['accounts_v2ray'], 'icon' => 'bx-rocket', 'tone' => 'amber'],
        ['label' => __('menu.accounts_anyconnect'), 'value' => $stats['accounts_anyconnect'] ?? 0, 'icon' => 'bx-network-chart', 'tone' => 'cyan'],
    ];

    $paymentRoute = match ($panel) {
        'admin' => 'admin.payment-requests.index',
        'agent' => 'agent.payment-requests.index',
        default => 'seller.payment-requests.index',
    };
@endphp

<div class="{{ $heroClass }}">
    <div class="row align-items-center g-3 position-relative" style="z-index:1">
        <div class="col-lg-8">
            <h2>{{ __('dashboard.welcome', ['name' => $user->full_name ?: $user->username]) }}</h2>
            <p>{{ $subtitle }}</p>
        </div>
        @if (isset($stats['wallet_balance']))
            <div class="col-lg-4">
                <div class="hero-wallet text-lg-end text-start">
                    <div class="label">{{ __('wallet.remaining_balance') }}</div>
                    <div class="amount">{{ format_toman($stats['wallet_balance']) }}</div>
                    @if ($stats['wallet_infinite'] ?? false)
                        <div class="label mt-1">{{ __('wallet.infinite_hint') }}</div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>

@if (($stats['pending_payments'] ?? 0) > 0 && \Illuminate\Support\Facades\Route::has($paymentRoute))
    <div class="panel-pending-banner">
        <div>
            <i class="bx bx-error-circle align-middle text-warning"></i>
            <strong>{{ __('dashboard.pending_alert', ['count' => persian_digits($stats['pending_payments'])]) }}</strong>
        </div>
        <a href="{{ route($paymentRoute) }}" class="btn btn-sm btn-warning">{{ __('dashboard.review_payments') }}</a>
    </div>
@endif

@if ($panel === 'admin')
    @include('shared.dashboard.partials.system-health-monitor', ['initialHealth' => $systemHealth ?? null])
    @include('shared.dashboard.partials.server-monitor')
@endif

@if (in_array($panel, ['agent', 'seller'], true) && ! empty($broadcastBanner['featured']))
    @include('shared.dashboard.partials.broadcast-banner', ['banner' => $broadcastBanner])
@endif

@if ($quickActions->isNotEmpty())
    <h6 class="panel-dash-section-title">{{ __('dashboard.quick_actions') }}</h6>
    <div class="row g-3 panel-quick-grid">
        @foreach ($quickActions as $action)
            <div class="col-6 col-md-4 col-xl-2">
                @if (($action['modal'] ?? null) === 'staff_create_account')
                    <button type="button" class="panel-quick-action w-100 text-start" data-staff-create-account-open>
                        <div class="icon panel-tone-{{ $action['tone'] }}"><i class="bx {{ $action['icon'] }}"></i></div>
                        <p class="title mb-0">{{ $action['label'] }}</p>
                    </button>
                @else
                    <a href="{{ route($action['route']) }}" class="panel-quick-action">
                        <div class="icon panel-tone-{{ $action['tone'] }}"><i class="bx {{ $action['icon'] }}"></i></div>
                        <p class="title mb-0">{{ $action['label'] }}</p>
                    </a>
                @endif
            </div>
        @endforeach
    </div>
@endif

<h6 class="panel-dash-section-title">{{ __('dashboard.overview') }}</h6>
<div class="row g-3 panel-kpi-grid">
    @foreach ($kpiCards as $card)
        <div class="col-sm-6 col-xl-3">
            <div class="panel-kpi-card panel-tone-{{ $card['tone'] }}">
                <div class="top">
                    <div>
                        <div class="label">{{ $card['title'] }}</div>
                        <div class="value">{{ $card['value'] }}</div>
                        @if ($card['hint'])
                            <div class="hint">{{ $card['hint'] }}</div>
                        @endif
                    </div>
                    <div class="icon"><i class="bx {{ $card['icon'] }}"></i></div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<h6 class="panel-dash-section-title">{{ __('dashboard.service_breakdown') }}</h6>
<div class="panel-service-row">
    @foreach ($serviceTypes as $service)
        <div class="panel-service-pill panel-tone-{{ $service['tone'] }}">
            <div class="icon"><i class="bx {{ $service['icon'] }}"></i></div>
            <div>
                <div class="name">{{ $service['label'] }}</div>
                <div class="count">{{ persian_digits($service['value']) }}</div>
            </div>
        </div>
    @endforeach
</div>

<h6 class="panel-dash-section-title">{{ __('dashboard.analytics') }}</h6>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="panel-chart-card">
            <div class="card-head">{{ __('accounts.trend_chart') }}</div>
            <div class="card-body">
                @if (count($trendLabels))
                    <canvas id="chart-trend" height="110" style="cursor:pointer"></canvas>
                    <p class="text-muted small mb-0 mt-2">{{ __('accounts.trend_chart_hint') }}</p>
                @else
                    <div class="panel-chart-empty">{{ __('dashboard.no_chart_data') }}</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="panel-chart-card">
            <div class="card-head">{{ __('accounts.status_chart') }}</div>
            <div class="card-body">
                @if (count($statusLabels))
                    <canvas id="chart-status" height="180"></canvas>
                @else
                    <div class="panel-chart-empty">{{ __('dashboard.no_chart_data') }}</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="panel-chart-card">
            <div class="card-head">{{ __('accounts.category_chart') }}</div>
            <div class="card-body">
                @if (array_sum($categoryValues) > 0)
                    <canvas id="chart-category" height="90"></canvas>
                @else
                    <div class="panel-chart-empty">{{ __('dashboard.no_chart_data') }}</div>
                @endif
            </div>
        </div>
    </div>
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

@if ($isStaffPanel ?? in_array($panel, ['agent', 'seller'], true))
    @include('shared.accounts.create-modal', [
        'prefix' => $prefix ?? $panel,
        'accountOwners' => $accountOwners ?? collect(),
        'openCreateModal' => $openCreateModal ?? false,
    ])
@endif
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    Chart.defaults.font.family = 'Tahoma, sans-serif';
    Chart.defaults.color = '#64748b';

    var trendLabels = @json($trendLabels);
    var trendValues = @json($trendValues);
    var trendByCategory = @json($trendByCategory);
    var trendDetails = @json($trendDetails);
    var trendCategoryLabels = @json($trendCategoryLabels);
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
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function openTrendDayModal(index) {
        if (!trendModal || !trendModalBody || !trendDetails[index]) return;

        var day = trendDetails[index];
        var dateLabel = day.jalali || day.label || '';
        trendModalTitle.textContent = trendTitleTpl.replace(':date', dateLabel);

        if (!day.total) {
            trendModalBody.innerHTML = '<p class="text-muted mb-0">' + escapeHtml(trendEmptyLabel) + '</p>';
        } else {
            var html = '<p class="mb-3"><strong>' + escapeHtml(trendTotalTpl.replace(':count', String(day.total))) + '</strong></p>';
            ['wireguard', 'ppp', 'v2ray'].forEach(function (key) {
                var block = (day.categories && day.categories[key]) || {};
                var total = Number(block.total || 0);
                var label = block.label || (trendCategoryLabels[key] || key);
                html += '<div class="mb-3">';
                html += '<h6 class="mb-2">' + escapeHtml(label) + ' <span class="badge bg-secondary">' + total + '</span></h6>';
                if (!total || !block.items || !block.items.length) {
                    html += '<p class="text-muted small mb-0">—</p>';
                } else {
                    html += '<div class="table-responsive"><table class="table table-sm table-bordered mb-0">';
                    html += '<thead><tr><th>' + escapeHtml(colServer) + '</th><th>' + escapeHtml(colPackage) + '</th><th class="text-center">' + escapeHtml(colCount) + '</th></tr></thead><tbody>';
                    block.items.forEach(function (item) {
                        html += '<tr>';
                        html += '<td>' + escapeHtml(item.server) + '</td>';
                        html += '<td>' + escapeHtml(item.package) + '</td>';
                        html += '<td class="text-center">' + Number(item.count || 0) + '</td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table></div>';
                }
                html += '</div>';
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
        trendModal.querySelectorAll('.dashboard-trend-day-modal__close').forEach(function (btn) {
            btn.addEventListener('click', closeTrendDayModal);
        });
        trendModal.addEventListener('click', function (e) {
            if (e.target === trendModal) closeTrendDayModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !trendModal.hidden) closeTrendDayModal();
        });
    }

    var trendEl = document.getElementById('chart-trend');
    if (trendEl && trendLabels.length) {
        var trendChart = new Chart(trendEl, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: @json(__('accounts.new_accounts')),
                    data: trendValues,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.12)',
                    borderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    pointBackgroundColor: '#4f46e5',
                    tension: 0.35,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                onClick: function (evt, elements) {
                    if (!elements || !elements.length) return;
                    openTrendDayModal(elements[0].index);
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = Number(ctx.parsed.y || 0);
                                var row = trendByCategory[ctx.dataIndex] || {};
                                var wg = Number(row.wireguard || 0);
                                var ppp = Number(row.ppp || 0);
                                var v2ray = Number(row.v2ray || 0);
                                return [
                                    @json(__('accounts.new_accounts')) + ': ' + total,
                                    (trendCategoryLabels.wireguard || 'WireGuard') + ': ' + wg,
                                    (trendCategoryLabels.ppp || 'PPP') + ': ' + ppp,
                                    (trendCategoryLabels.v2ray || 'V2ray') + ': ' + v2ray
                                ];
                            },
                            afterBody: function () {
                                return [@json(__('accounts.trend_chart_hint'))];
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
        void trendChart;
    }

    var statusLabels = @json($statusLabels);
    var statusValues = @json($statusValues);
    var statusByCategory = @json($statusByCategory);
    var statusEl = document.getElementById('chart-status');
    if (statusEl && statusLabels.length) {
        new Chart(statusEl, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusValues,
                    backgroundColor: ['#22c55e', '#3b82f6', '#f59e0b', '#ef4444', '#94a3b8'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                cutout: '62%',
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14 } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = Number(ctx.parsed || 0);
                                var row = statusByCategory[ctx.dataIndex] || {};
                                var wg = Number(row.wireguard || 0);
                                var ppp = Number(row.ppp || 0);
                                var v2ray = Number(row.v2ray || 0);
                                var statusName = ctx.label || '';
                                return [
                                    statusName + ': ' + total,
                                    (trendCategoryLabels.wireguard || 'WireGuard') + ': ' + wg,
                                    (trendCategoryLabels.ppp || 'PPP') + ': ' + ppp,
                                    (trendCategoryLabels.v2ray || 'V2ray') + ': ' + v2ray
                                ];
                            }
                        }
                    }
                }
            }
        });
    }

    var categoryLabels = @json($categoryLabels);
    var categoryValues = @json($categoryValues);
    var categoryEl = document.getElementById('chart-category');
    if (categoryEl && categoryValues.some(function (v) { return v > 0; })) {
        new Chart(categoryEl, {
            type: 'bar',
            data: {
                labels: categoryLabels,
                datasets: [{
                    label: @json(__('menu.accounts')),
                    data: categoryValues,
                    backgroundColor: ['#22c55e', '#3b82f6', '#f59e0b'],
                    borderRadius: 8,
                    maxBarThickness: 56
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    }
});
</script>
@endpush
