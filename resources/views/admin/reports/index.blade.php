@extends('layouts.panel')

@section('page_title', __('menu.reports'))

@php
    use App\Enums\ServiceType;
    $statusLabels = [
        'active' => [__('accounts.status_active'), 'success'],
        'disabled' => [__('accounts.status_disabled'), 'secondary'],
        'expired' => [__('accounts.status_expired'), 'danger'],
        'exhausted' => [__('accounts.status_exhausted'), 'warning'],
        'pending' => [__('accounts.status_pending'), 'info'],
    ];
    $bar = function (int $value, int $total, string $color = 'primary') {
        $pct = $total > 0 ? round($value / $total * 100, 1) : 0;
        return '<div class="progress" style="height:8px;"><div class="progress-bar bg-'.$color.'" style="width:'.$pct.'%"></div></div>'
            .'<small class="text-muted">'.persian_digits($value).' ('.persian_digits($pct).'٪)</small>';
    };
@endphp

@section('panel_content')

{{-- ============ Date filter ============ --}}
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">{{ __('ui.date_from_jalali') }}</label>
                <x-form.jalali-date name="from" :value="$fromInput" />
            </div>
            <div class="col-md-3">
                <label class="form-label">{{ __('ui.date_to_jalali') }}</label>
                <x-form.jalali-date name="to" :value="$toInput" />
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bx bx-filter-alt"></i> {{ __('ui.apply_range') }}</button>
                <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">{{ __('ui.last_30_days') }}</a>
            </div>
            <div class="col-md-3 text-md-end">
                <span class="text-muted small">{{ __('ui.range_from_to', [':from' => jalali_date($from,'Y/m/d'), ':to' => jalali_date($to,'Y/m/d')]) }}</span>
            </div>
        </form>
    </div>
</div>

{{-- ============ Period KPIs ============ --}}
<h5 class="text-muted mb-2"><i class="bx bx-calendar"></i> {{ __('ui.reports_period_performance') }}</h5>
<div class="row">
    <x-stat-card title="{{ __('ui.reports_total_revenue') }}" :value="format_toman($period['revenueTotal'])" icon="bx-wallet" color="success"
                 :hint="__('ui.reports_revenue_hint', [':new' => format_toman($period['revenueNew']), ':renewal' => format_toman($period['revenueRenewal'])])" />
    <x-stat-card title="{{ __('ui.reports_new_accounts') }}" :value="persian_digits($period['newAccounts'])" icon="bx-plus-circle" color="primary"
                 :hint="__('ui.reports_renewals_in_period', [':count' => persian_digits($period['countRenewalInvoices'])])" />
    <x-stat-card title="{{ __('ui.reports_wallet_deposits') }}" :value="format_toman($period['deposits'])" icon="bx-credit-card" color="warning"
                 :hint="__('ui.reports_deposits_hint')" />
    <x-stat-card title="{{ __('ui.reports_refunds') }}" :value="persian_digits($period['refundsCount'])" icon="bx-undo" color="danger"
                 :hint="__('ui.amount_x', [':amount' => format_toman($period['refundsAmount'])])" />
</div>
<div class="row">
    <x-stat-card title="{{ __('ui.reports_new_agents') }}" :value="persian_digits($period['newAgents'])" icon="bx-user-pin" color="primary" />
    <x-stat-card title="{{ __('ui.reports_new_sellers') }}" :value="persian_digits($period['newSellers'])" icon="bx-user" color="primary" />
    <x-stat-card title="{{ __('ui.reports_new_clients') }}" :value="persian_digits($period['newClients'])" icon="bx-group" color="primary" />
    <x-stat-card title="{{ __('ui.reports_admin_revenue') }}" :value="format_toman($period['adminRevenue'])" icon="bx-trending-up" color="success"
                 :hint="__('ui.reports_agent_margin_hint', [':amount' => format_toman($period['agentMargin'])])" />
</div>

{{-- ============ Trends ============ --}}
<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-line-chart"></i> {{ __('ui.reports_accounts_created') }} ({{ $trend['granularity']==='monthly' ? __('ui.monthly') : __('ui.daily') }})</h6></div>
            <div class="card-body"><canvas id="chartAccounts" height="120"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-bar-chart-alt-2"></i> {{ __('ui.revenue') }} ({{ $trend['granularity']==='monthly' ? __('ui.monthly') : __('ui.daily') }})</h6></div>
            <div class="card-body"><canvas id="chartRevenue" height="120"></canvas></div>
        </div>
    </div>
</div>

{{-- ============ Current account state ============ --}}
<h5 class="text-muted mb-2 mt-2"><i class="bx bx-server"></i> {{ __('ui.reports_current_account_state', [':total' => persian_digits($state['totalAccounts'])]) }}</h5>
<div class="row">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">{{ __('ui.reports_by_status') }}</h6></div>
            <div class="card-body">
                <table class="table table-sm align-middle mb-0">
                    @foreach ($statusLabels as $key => [$label, $color])
                        @php $c = (int) ($state['accByStatus'][$key] ?? 0); @endphp
                        <tr>
                            <td style="width:120px;"><span class="badge bg-{{ $color }}">{{ $label }}</span></td>
                            <td>{!! $bar($c, $state['totalAccounts'], $color) !!}</td>
                        </tr>
                    @endforeach
                </table>
                <div class="mt-3 d-flex gap-3 flex-wrap">
                    <span class="small">⏳ {{ __('ui.reports_expiring_soon', [':days' => persian_digits($state['thresholdDays'])]) }} <strong class="text-danger">{{ persian_digits($state['expiringSoon']) }}</strong></span>
                    <span class="small">📉 {{ __('ui.reports_low_volume', [':size' => format_data_size($state['thresholdBytes'])]) }} <strong class="text-warning">{{ persian_digits($state['lowVolume']) }}</strong></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">{{ __('ui.reports_by_service_type') }}</h6></div>
            <div class="card-body">
                <table class="table table-sm align-middle mb-0">
                    @foreach ($state['accByService'] as $key => $c)
                        <tr>
                            <td style="width:150px;">{{ ServiceType::tryFrom($key)?->label() ?? $key }}</td>
                            <td>{!! $bar((int) $c, $state['totalAccounts'], 'info') !!}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">{{ __('ui.reports_accounts_by_server') }}</h6></div>
            <div class="card-body">
                <table class="table table-sm align-middle mb-0">
                    @forelse ($state['accByServer'] as $row)
                        <tr><td>{{ $row['name'] }}</td><td>{!! $bar($row['count'], $state['totalAccounts'], 'primary') !!}</td></tr>
                    @empty
                        <tr><td class="text-muted">—</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">{{ __('ui.reports_top_packages') }}</h6></div>
            <div class="card-body">
                <table class="table table-sm align-middle mb-0">
                    @forelse ($state['accByPackage'] as $row)
                        <tr><td>{{ $row['name'] }}</td><td>{!! $bar($row['count'], $state['totalAccounts'], 'success') !!}</td></tr>
                    @empty
                        <tr><td class="text-muted">—</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </div>
</div>

{{-- ============ Resellers ============ --}}
<h5 class="text-muted mb-2 mt-2"><i class="bx bx-group"></i> {{ __('ui.reports_agents_and_sellers') }}</h5>
<div class="row">
    <x-stat-card title="{{ __('ui.agents_plural') }}" :value="persian_digits($users['totalAgents'])" icon="bx-user-pin" color="primary"
                 :hint="__('ui.total_balance_x', [':amount' => format_toman($users['walletAgents'])])" />
    <x-stat-card title="{{ __('ui.sellers_plural') }}" :value="persian_digits($users['totalSellers'])" icon="bx-user" color="primary"
                 :hint="__('ui.total_balance_x', [':amount' => format_toman($users['walletSellers'])])" />
    <x-stat-card title="{{ __('ui.clients_plural') }}" :value="persian_digits($users['totalClients'])" icon="bx-group" color="primary" />
    <x-stat-card title="{{ __('ui.reports_total_wallet_balance') }}" :value="format_toman(bcadd((string)$users['walletAgents'], (string)$users['walletSellers'], 2))" icon="bx-wallet" color="success" />
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">{{ __('ui.reports_top_sellers') }}</h6></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>{{ __('roles.seller') }}</th><th>{{ __('ui.col_total_accounts') }}</th><th>{{ __('app.active') }}</th></tr></thead>
                        <tbody>
                            @forelse ($users['topSellers'] as $s)
                                <tr><td>{{ $s['name'] }}</td><td>{{ persian_digits($s['total']) }}</td><td><span class="text-success">{{ persian_digits($s['active']) }}</span></td></tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">—</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">{{ __('ui.reports_top_sellers_revenue') }}</h6></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>{{ __('roles.seller') }}</th><th>{{ __('ui.revenue') }}</th><th>{{ __('ui.col_invoice') }}</th></tr></thead>
                        <tbody>
                            @forelse ($users['topSellersRevenue'] as $s)
                                <tr><td>{{ $s['name'] }}</td><td>{{ format_toman($s['revenue']) }}</td><td>{{ persian_digits($s['count']) }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">{{ __('ui.reports_no_invoices_in_period') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    if (typeof window.Chart === 'undefined') return;
    var labels = @json($trend['labels']);
    var accounts = @json($trend['accounts']);
    var revenue = @json($trend['revenue']);
    var faFont = { family: 'inherit' };

    var a = document.getElementById('chartAccounts');
    if (a) new Chart(a, {
        type: 'line',
        data: { labels: labels, datasets: [{ label: @json(__('ui.chart_new_accounts')), data: accounts, borderColor: '#556ee6', backgroundColor: 'rgba(85,110,230,.12)', fill: true, tension: .3, pointRadius: 2 }] },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { x: { ticks: { maxTicksLimit: 12 } } } }
    });

    var r = document.getElementById('chartRevenue');
    if (r) new Chart(r, {
        type: 'bar',
        data: { labels: labels, datasets: [{ label: @json(__('ui.revenue')), data: revenue, backgroundColor: '#34c38f' }] },
        options: { responsive: true, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { try { return new Intl.NumberFormat('fa-IR').format(c.raw) + ' ' + @json(__('packages.toman')); } catch (e) { return c.raw; } } } } }, scales: { x: { ticks: { maxTicksLimit: 12 } }, y: { ticks: { callback: function (v) { try { return new Intl.NumberFormat('fa-IR').format(v); } catch (e) { return v; } } } } } }
    });
})();
</script>
@endpush
