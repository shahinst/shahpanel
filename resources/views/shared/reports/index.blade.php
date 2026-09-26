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

    // Self-contained inline SVG charts + a floating JS tooltip (no bundle dependency).
    // Each chart adds full-height transparent "hit columns" carrying data-tip so the
    // value shows whenever the cursor is anywhere over that day's column.
    $W = 600; $H = 170; $pad = 22;
    // اگر ارز داده شود مقدار پولی است و با واحد همان ارز نمایش داده می‌شود؛ در غیر این صورت یک شمارش ساده است.
    $mkTip = fn ($label, $v, $currency) => $label.' — '.($currency ? format_money($v, $currency) : persian_digits((int) $v));
    $grid = function ($ih) use ($W, $pad) {
        $g = '';
        foreach ([0, .5, 1] as $f) { $gy = round($pad + $ih * $f, 1); $g .= '<line x1="'.$pad.'" y1="'.$gy.'" x2="'.($W - $pad).'" y2="'.$gy.'" stroke="currentColor" stroke-opacity=".08"/>'; }
        return $g;
    };
    $hitCols = function (array $labels, array $vals, ?string $currency, float $iw, float $ih) use ($W, $pad, $mkTip) {
        $n = count($vals); $slot = $iw / max(1, $n); $h = '';
        foreach ($vals as $i => $v) {
            $cx = $pad + ($n > 1 ? $i / ($n - 1) * $iw : $iw / 2);
            $hx = round(min($W - $pad - $slot, max($pad, $cx - $slot / 2)), 1);
            $h .= '<rect class="rep-hit" x="'.$hx.'" y="'.$pad.'" width="'.round($slot, 1).'" height="'.round($ih, 1).'" fill="transparent" data-tip="'.e($mkTip($labels[$i] ?? '', $v, $currency)).'"></rect>';
        }
        return $h;
    };
    $svgArea = function (array $labels, array $vals, string $color, ?string $currency = null) use ($W, $H, $pad, $grid, $hitCols) {
        $n = count($vals);
        if ($n === 0 || array_sum($vals) == 0) {
            return '<div class="text-muted small text-center py-5">'.e(__('ui.no_data_in_period')).'</div>';
        }
        $max = max($vals); $max = $max > 0 ? $max : 1;
        $iw = $W - 2 * $pad; $ih = $H - 2 * $pad;
        $x = fn ($i) => $pad + ($n > 1 ? $i / ($n - 1) * $iw : $iw / 2);
        $y = fn ($v) => $pad + $ih - ($v / $max) * $ih;
        $pts = []; $dots = '';
        foreach ($vals as $i => $v) {
            $px = round($x($i), 1); $py = round($y($v), 1);
            $pts[] = "$px,$py";
            $dots .= '<circle cx="'.$px.'" cy="'.$py.'" r="2.5" fill="'.$color.'"/>';
        }
        $line = implode(' ', $pts);
        $baseY = $pad + $ih;
        $area = $pad.','.$baseY.' '.$line.' '.round($x($n - 1), 1).','.$baseY;
        $maxLbl = $currency ? format_money($max, $currency) : persian_digits((int) $max);
        return '<svg viewBox="0 0 '.$W.' '.$H.'" width="100%" preserveAspectRatio="none" style="color:#889;overflow:visible;">'
            .$grid($ih)
            .'<polygon points="'.$area.'" fill="'.$color.'" fill-opacity=".12"/>'
            .'<polyline points="'.$line.'" fill="none" stroke="'.$color.'" stroke-width="2"/>'
            .$dots
            .'<text x="'.$pad.'" y="'.($pad - 6).'" font-size="9" fill="currentColor" fill-opacity=".6">'.e(__('ui.max_x', ['value' => $maxLbl])).'</text>'
            .$hitCols($labels, $vals, $currency, $iw, $ih)
            .'</svg>';
    };
    $svgBars = function (array $labels, array $vals, string $color, string $currency) use ($W, $H, $pad, $grid, $hitCols, $mkTip) {
        $n = count($vals);
        if ($n === 0 || array_sum($vals) == 0) {
            return '<div class="text-muted small text-center py-5">'.e(__('ui.no_data_in_period')).'</div>';
        }
        $max = max($vals); $max = $max > 0 ? $max : 1;
        $iw = $W - 2 * $pad; $ih = $H - 2 * $pad;
        $bw = $iw / $n * 0.7; $gap = $iw / $n;
        $baseY = $pad + $ih; $bars = '';
        foreach ($vals as $i => $v) {
            $bh = ($v / $max) * $ih;
            $bx = round($pad + $i * $gap + ($gap - $bw) / 2, 1);
            $by = round($baseY - $bh, 1);
            $bars .= '<rect x="'.$bx.'" y="'.$by.'" width="'.round($bw, 1).'" height="'.round($bh, 1).'" rx="1.5" fill="'.$color.'"></rect>';
        }
        return '<svg viewBox="0 0 '.$W.' '.$H.'" width="100%" preserveAspectRatio="none" style="color:#889;overflow:visible;">'
            .$grid($ih).$bars
            .'<text x="'.$pad.'" y="'.($pad - 6).'" font-size="9" fill="currentColor" fill-opacity=".6">'.e(__('ui.max_x', ['value' => format_money($max, $currency)])).'</text>'
            .$hitCols($labels, $vals, $currency, $iw, $ih)
            .'</svg>';
    };
@endphp

@section('panel_content')
<style>
    .rep-hit { cursor: crosshair; }
    .rep-hit:hover { fill: currentColor; fill-opacity: .07; }
    #rep-tip { position: fixed; z-index: 10800; pointer-events: none; display: none;
        background: rgba(20,22,34,.94); color: #fff; padding: 4px 9px; border-radius: 6px;
        font-size: 12px; white-space: nowrap; box-shadow: 0 4px 14px rgba(0,0,0,.3); }
</style>

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
                <a href="{{ route($panel.'.reports.index') }}" class="btn btn-outline-secondary btn-sm">{{ __('ui.last_30_days') }}</a>
            </div>
            <div class="col-md-3 text-md-end">
                <span class="text-muted small">{{ __('ui.range_from_to', ['from' => jalali_date($from,'Y/m/d'), 'to' => jalali_date($to,'Y/m/d')]) }}</span>
            </div>
        </form>
    </div>
</div>

{{-- ============ KPIs (role-aware) ============ --}}
<h5 class="text-muted mb-2"><i class="bx bx-calendar"></i> {{ __('ui.reports_period_performance') }}</h5>
<div class="row">
    @foreach ($kpis as $k)
        <x-stat-card :title="$k['title']" :value="$k['value']" :icon="$k['icon']" :color="$k['color']" :hint="$k['hint']" />
    @endforeach
</div>

{{-- ============ Trends ============ --}}
<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-line-chart"></i> {{ __('ui.reports_accounts_created') }} ({{ $trend['granularity']==='monthly' ? __('ui.monthly') : __('ui.daily') }})</h6></div>
            <div class="card-body">{!! $svgArea($trend['labels'], $trend['accounts'], '#556ee6', null) !!}</div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-bar-chart-alt-2"></i> {{ $scope==='seller' ? __('ui.purchase') : __('ui.revenue') }} ({{ $trend['granularity']==='monthly' ? __('ui.monthly') : __('ui.daily') }})</h6></div>
            <div class="card-body">
                {{-- برای هر ارز یک نمودار جدا رسم می‌شود تا مبالغ ارزهای مختلف با هم جمع نشوند. --}}
                @foreach ($trend['revenue'] as $revenueSeries)
                    @if (! $loop->first)
                        <hr class="my-3">
                    @endif
                    <div class="text-muted small mb-1">{{ \App\Enums\MoneyCurrency::normalize($revenueSeries['currency'])->label() }}</div>
                    {!! $svgBars($trend['labels'], $revenueSeries['values'], '#34c38f', $revenueSeries['currency']) !!}
                @endforeach
            </div>
        </div>
    </div>
</div>

{{-- ============ Current account state ============ --}}
<h5 class="text-muted mb-2 mt-2"><i class="bx bx-list-ul"></i> {{ __('ui.reports_current_account_state_scoped', ['scope' => $scope==='admin' ? __('ui.whole_system') : __('ui.your_accounts'), 'total' => persian_digits($state['totalAccounts'])]) }}</h5>
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
                    <span class="small">⏳ {{ __('ui.reports_expiring_soon', ['days' => persian_digits($state['thresholdDays'])]) }} <strong class="text-danger">{{ persian_digits($state['expiringSoon']) }}</strong></span>
                    <span class="small">📉 {{ __('ui.reports_low_volume', ['size' => format_data_size($state['thresholdBytes'])]) }} <strong class="text-warning">{{ persian_digits($state['lowVolume']) }}</strong></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">{{ __('ui.reports_by_service_type') }}</h6></div>
            <div class="card-body">
                <table class="table table-sm align-middle mb-0">
                    @forelse ($state['accByService'] as $key => $c)
                        <tr>
                            <td style="width:150px;">{{ ServiceType::tryFrom($key)?->label() ?? $key }}</td>
                            <td>{!! $bar((int) $c, $state['totalAccounts'], 'info') !!}</td>
                        </tr>
                    @empty
                        <tr><td class="text-muted">—</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row">
    @if ($flags['showServers'])
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
    @endif
    <div class="{{ $flags['showServers'] ? 'col-lg-6' : 'col-12' }}">
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

{{-- ============ Resellers (admin: all, agent: own) — hidden for sellers ============ --}}
@if ($flags['showResellers'])
<h5 class="text-muted mb-2 mt-2"><i class="bx bx-user"></i> {{ $scope==='admin' ? __('ui.sellers_plural') : __('ui.your_sellers') }}</h5>
<div class="row">
    <x-stat-card title="{{ __('ui.sellers_count') }}" :value="persian_digits($resellers['sellersCount'])" icon="bx-user" color="primary"
                 :hint="__('ui.total_wallet_balance_x', ['amount' => (collect($resellers['walletSellers'])->map(fn (string $amount, string $code): string => format_money($amount, $code))->implode(' + ') ?: format_money('0', \App\Enums\MoneyCurrency::default()))])" />
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
                            @forelse ($resellers['topSellers'] as $s)
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
                        <thead><tr><th>{{ __('roles.seller') }}</th><th>{{ __('ui.col_turnover') }}</th><th>{{ __('ui.col_invoice') }}</th></tr></thead>
                        <tbody>
                            @forelse ($resellers['topSellersRevenue'] as $s)
                                <tr><td>{{ $s['name'] }}</td><td>{{ collect($s['revenue'])->map(fn (string $amount, string $code): string => format_money($amount, $code))->implode(' + ') }}</td><td>{{ persian_digits($s['count']) }}</td></tr>
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
@endif
@endsection

@push('scripts')
<script>
(function () {
    // Floating tooltip for the SVG report charts — self-contained, no dependencies.
    var tip = document.getElementById('rep-tip');
    if (!tip) { tip = document.createElement('div'); tip.id = 'rep-tip'; document.body.appendChild(tip); }
    function place(e) { tip.style.left = (e.clientX + 14) + 'px'; tip.style.top = (e.clientY + 14) + 'px'; }
    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest && e.target.closest('[data-tip]');
        if (el) { tip.textContent = el.getAttribute('data-tip'); tip.style.display = 'block'; place(e); }
    });
    document.addEventListener('mousemove', function (e) {
        if (tip.style.display !== 'block') return;
        var el = e.target.closest && e.target.closest('[data-tip]');
        if (el) { place(e); } else { tip.style.display = 'none'; }
    });
    document.addEventListener('mouseout', function (e) {
        if (e.target.closest && e.target.closest('[data-tip]')) tip.style.display = 'none';
    });
})();
</script>
@endpush
