@extends('layouts.panel')

@section('page_title', __('menu.reports'))

@php
    use App\Enums\ServiceType;
    $statusLabels = [
        'active' => ['فعال', 'success'],
        'disabled' => ['غیرفعال', 'secondary'],
        'expired' => ['منقضی', 'danger'],
        'exhausted' => ['اتمام حجم', 'warning'],
        'pending' => ['در انتظار', 'info'],
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
                <label class="form-label">از تاریخ (شمسی)</label>
                <x-form.jalali-date name="from" :value="$fromInput" />
            </div>
            <div class="col-md-3">
                <label class="form-label">تا تاریخ (شمسی)</label>
                <x-form.jalali-date name="to" :value="$toInput" />
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bx bx-filter-alt"></i> اعمال بازه</button>
                <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">۳۰ روز اخیر</a>
            </div>
            <div class="col-md-3 text-md-end">
                <span class="text-muted small">بازه: {{ jalali_date($from,'Y/m/d') }} تا {{ jalali_date($to,'Y/m/d') }}</span>
            </div>
        </form>
    </div>
</div>

{{-- ============ Period KPIs ============ --}}
<h5 class="text-muted mb-2"><i class="bx bx-calendar"></i> عملکرد بازه‌ی انتخابی</h5>
<div class="row">
    <x-stat-card title="درآمد کل (فاکتورها)" :value="format_toman($period['revenueTotal'])" icon="bx-wallet" color="success"
                 :hint="'جدید: '.format_toman($period['revenueNew']).' | تمدید: '.format_toman($period['revenueRenewal'])" />
    <x-stat-card title="اکانت‌های جدید" :value="persian_digits($period['newAccounts'])" icon="bx-plus-circle" color="primary"
                 :hint="persian_digits($period['countRenewalInvoices']).' تمدید در این بازه'" />
    <x-stat-card title="شارژ کیف‌پول‌ها" :value="format_toman($period['deposits'])" icon="bx-credit-card" color="warning"
                 :hint="'واریزی نماینده/فروشنده'" />
    <x-stat-card title="برگشت از خرید" :value="persian_digits($period['refundsCount'])" icon="bx-undo" color="danger"
                 :hint="'مبلغ: '.format_toman($period['refundsAmount'])" />
</div>
<div class="row">
    <x-stat-card title="نمایندگان جدید" :value="persian_digits($period['newAgents'])" icon="bx-user-pin" color="primary" />
    <x-stat-card title="فروشندگان جدید" :value="persian_digits($period['newSellers'])" icon="bx-user" color="primary" />
    <x-stat-card title="مشتریان جدید" :value="persian_digits($period['newClients'])" icon="bx-group" color="primary" />
    <x-stat-card title="درآمد ادمین / سود نماینده‌ها" :value="format_toman($period['adminRevenue'])" icon="bx-trending-up" color="success"
                 :hint="'سود نماینده‌ها: '.format_toman($period['agentMargin'])" />
</div>

{{-- ============ Trends ============ --}}
<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-line-chart"></i> اکانت‌های ساخته‌شده ({{ $trend['granularity']==='monthly'?'ماهانه':'روزانه' }})</h6></div>
            <div class="card-body"><canvas id="chartAccounts" height="120"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-bar-chart-alt-2"></i> درآمد ({{ $trend['granularity']==='monthly'?'ماهانه':'روزانه' }})</h6></div>
            <div class="card-body"><canvas id="chartRevenue" height="120"></canvas></div>
        </div>
    </div>
</div>

{{-- ============ Current account state ============ --}}
<h5 class="text-muted mb-2 mt-2"><i class="bx bx-server"></i> وضعیت فعلی اکانت‌ها (کل سیستم: {{ persian_digits($state['totalAccounts']) }})</h5>
<div class="row">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">بر اساس وضعیت</h6></div>
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
                    <span class="small">⏳ نزدیک انقضا (≤{{ persian_digits($state['thresholdDays']) }} روز): <strong class="text-danger">{{ persian_digits($state['expiringSoon']) }}</strong></span>
                    <span class="small">📉 حجم کم (<{{ format_data_size($state['thresholdBytes']) }}): <strong class="text-warning">{{ persian_digits($state['lowVolume']) }}</strong></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">بر اساس نوع سرویس</h6></div>
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
            <div class="card-header"><h6 class="mb-0">اکانت‌ها بر اساس سرور</h6></div>
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
            <div class="card-header"><h6 class="mb-0">پرفروش‌ترین پکیج‌ها (تعداد اکانت)</h6></div>
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
<h5 class="text-muted mb-2 mt-2"><i class="bx bx-group"></i> نمایندگان و فروشندگان</h5>
<div class="row">
    <x-stat-card title="نمایندگان" :value="persian_digits($users['totalAgents'])" icon="bx-user-pin" color="primary"
                 :hint="'موجودی کل: '.format_toman($users['walletAgents'])" />
    <x-stat-card title="فروشندگان" :value="persian_digits($users['totalSellers'])" icon="bx-user" color="primary"
                 :hint="'موجودی کل: '.format_toman($users['walletSellers'])" />
    <x-stat-card title="مشتریان" :value="persian_digits($users['totalClients'])" icon="bx-group" color="primary" />
    <x-stat-card title="موجودی کل کیف‌پول‌ها" :value="format_toman(bcadd((string)$users['walletAgents'], (string)$users['walletSellers'], 2))" icon="bx-wallet" color="success" />
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">فعال‌ترین فروشندگان (تعداد اکانت)</h6></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>فروشنده</th><th>کل اکانت</th><th>فعال</th></tr></thead>
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
            <div class="card-header"><h6 class="mb-0">پردرآمدترین فروشندگان (در بازه)</h6></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>فروشنده</th><th>درآمد</th><th>فاکتور</th></tr></thead>
                        <tbody>
                            @forelse ($users['topSellersRevenue'] as $s)
                                <tr><td>{{ $s['name'] }}</td><td>{{ format_toman($s['revenue']) }}</td><td>{{ persian_digits($s['count']) }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">در این بازه فاکتوری نیست</td></tr>
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
        data: { labels: labels, datasets: [{ label: 'اکانت جدید', data: accounts, borderColor: '#556ee6', backgroundColor: 'rgba(85,110,230,.12)', fill: true, tension: .3, pointRadius: 2 }] },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { x: { ticks: { maxTicksLimit: 12 } } } }
    });

    var r = document.getElementById('chartRevenue');
    if (r) new Chart(r, {
        type: 'bar',
        data: { labels: labels, datasets: [{ label: 'درآمد', data: revenue, backgroundColor: '#34c38f' }] },
        options: { responsive: true, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { try { return new Intl.NumberFormat('fa-IR').format(c.raw) + ' تومان'; } catch (e) { return c.raw; } } } } }, scales: { x: { ticks: { maxTicksLimit: 12 } }, y: { ticks: { callback: function (v) { try { return new Intl.NumberFormat('fa-IR').format(v); } catch (e) { return v; } } } } } }
    });
})();
</script>
@endpush
