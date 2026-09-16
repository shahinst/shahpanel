@extends('layouts.panel')

@section('page_title', 'قیمت‌گذاری پکیج‌ها')

@php
    // tier => current PackageDuration, per package, for the calc tab
    $tierMap = function ($package) {
        $m = [];
        foreach ($package->durations as $d) {
            $m[$d->tier->value] = $d;
        }
        return $m;
    };
@endphp

@section('panel_content')

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <button type="button" class="nav-link active" data-pp-tab="adjust"><i class="bx bx-slider"></i> افزایش و کاهش قیمت</button>
    </li>
    <li class="nav-item">
        <button type="button" class="nav-link" data-pp-tab="calc"><i class="bx bx-calculator"></i> محاسبه‌ی قیمت دوره‌ای (۳/۶/۱۲ ماهه)</button>
    </li>
</ul>

{{-- ==================== TAB 1: افزایش / کاهش ==================== --}}
<div data-pp-pane="adjust">
    <form method="POST" action="{{ route('admin.packages.pricing.apply') }}" id="adj-form">
        @csrf
        <div class="mb-3">
            <x-card>
                <div class="card-body">
                    <p class="text-muted small">
                        درصدی وارد کنید، پکیج‌ها را انتخاب کنید و «افزایش» یا «کاهش» را بزنید.
                        قیمت‌ها تمیز گرد می‌شوند (مثلاً ۱۰۲۹۴ → ۱۰۳۰۰). روی <strong>قیمت مشتری (خرده‌فروشی)</strong> اعمال می‌شود.
                    </p>
                    <div class="row g-3 align-items-end">
                        <div class="col-6 col-md-3">
                            <label class="form-label">درصد (٪)</label>
                            <input type="number" step="0.01" min="0.01" max="1000" dir="ltr" id="adj-percent"
                                   name="percent" class="form-control" value="{{ old('percent') }}" placeholder="مثلاً 10">
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label d-block">جهت</label>
                            <div class="btn-group" role="group">
                                <input type="radio" class="btn-check" name="direction" id="adj-inc" value="increase" @checked(old('direction', 'increase') === 'increase')>
                                <label class="btn btn-outline-success" for="adj-inc"><i class="bx bx-up-arrow-alt"></i> افزایش</label>
                                <input type="radio" class="btn-check" name="direction" id="adj-dec" value="decrease" @checked(old('direction') === 'decrease')>
                                <label class="btn btn-outline-danger" for="adj-dec"><i class="bx bx-down-arrow-alt"></i> کاهش</label>
                            </div>
                        </div>
                        <div class="col-12 col-md-5 text-md-end">
                            <span class="text-muted small me-2"><strong class="adj-count">0</strong> پکیج انتخاب شده</span>
                            <x-button type="submit"><i class="bx bx-check"></i> اعمال</x-button>
                        </div>
                    </div>
                    @error('percent') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
                </div>
            </x-card>
        </div>

        <x-card>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-adj-select="all">انتخاب همه</button>
                    <button type="button" class="btn btn-sm btn-outline-success" data-adj-select="active">انتخاب فعال‌ها</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-adj-select="none">لغو انتخاب</button>
                </div>
                @foreach (['active' => ['پکیج‌های فعال', $activePackages, 'success', 'فعال'], 'inactive' => ['پکیج‌های غیرفعال', $inactivePackages, 'secondary', 'غیرفعال']] as $key => $group)
                    @php [$title, $packages, $color, $badge] = $group; @endphp
                    @if ($packages->isNotEmpty())
                        <h6 class="text-muted border-bottom pb-2 mb-3 mt-3"><span class="badge bg-{{ $color }}">{{ $badge }}</span> {{ $title }} <span class="text-muted">({{ persian_digits($packages->count()) }})</span></h6>
                        <div class="table-responsive mb-2">
                            <table class="table table-sm table-hover align-middle">
                                <thead><tr><th style="width:36px;"></th><th>پکیج</th><th>قیمت فعلی → جدید</th></tr></thead>
                                <tbody>
                                    @foreach ($packages as $package)
                                        <tr>
                                            <td><input class="form-check-input adj-check" type="checkbox" name="package_ids[]" value="{{ $package->id }}" data-group="{{ $key }}"></td>
                                            <td><span class="fw-semibold">{{ $package->name }}</span> <span class="badge bg-{{ $color }} ms-1">{{ $badge }}</span></td>
                                            <td>
                                                @foreach ($package->durations as $duration)
                                                    @php $old = (float) $duration->price; @endphp
                                                    @if ($old > 0)
                                                        <div class="small mb-1"><span class="text-muted">{{ $duration->displayLabel() }}:</span>
                                                            <span dir="ltr">{{ format_money($old, $package->moneyCurrency()) }}</span> <i class="bx bx-left-arrow-alt"></i>
                                                            <strong class="adj-new" data-old="{{ $old }}" data-symbol="{{ $package->moneyCurrency()->symbol() }}" data-decimals="{{ $package->moneyCurrency()->displayDecimals() }}" dir="ltr">—</strong>
                                                        </div>
                                                    @endif
                                                @endforeach
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endforeach
            </div>
        </x-card>
    </form>
</div>

{{-- ==================== TAB 2: محاسبه‌ی دوره‌ای ==================== --}}
<div data-pp-pane="calc" style="display:none;">
    <form method="POST" action="{{ route('admin.packages.pricing.durations') }}" id="calc-form">
        @csrf
        <div class="mb-3">
            <x-card>
                <div class="card-body">
                    <p class="text-muted small">
                        قیمت ۳ / ۶ / ۱۲ ماهه‌ی هر پکیج از روی قیمت <strong>۱ماهه</strong> و ضرایب زیر ساخته و
                        <strong>فعال</strong> می‌شود. قیمت‌ها تمیز گرد می‌شوند.
                    </p>
                    <div class="row g-3 align-items-end">
                        <div class="col-4 col-md-2">
                            <label class="form-label">۳ ماهه ×</label>
                            <input type="number" step="0.1" min="0" dir="ltr" id="m3" name="mult_3m" class="form-control" value="{{ old('mult_3m', '3') }}">
                        </div>
                        <div class="col-4 col-md-2">
                            <label class="form-label">۶ ماهه ×</label>
                            <input type="number" step="0.1" min="0" dir="ltr" id="m6" name="mult_6m" class="form-control" value="{{ old('mult_6m', '5.5') }}">
                        </div>
                        <div class="col-4 col-md-2">
                            <label class="form-label">۱ ساله ×</label>
                            <input type="number" step="0.1" min="0" dir="ltr" id="m12" name="mult_1y" class="form-control" value="{{ old('mult_1y', '10') }}">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label d-block">حالت</label>
                            <div class="btn-group" role="group">
                                <input type="radio" class="btn-check" name="mode" id="mode-fill" value="fill" @checked(old('mode', 'fill') === 'fill')>
                                <label class="btn btn-outline-primary" for="mode-fill">فقط دوره‌های خالی</label>
                                <input type="radio" class="btn-check" name="mode" id="mode-ow" value="overwrite" @checked(old('mode') === 'overwrite')>
                                <label class="btn btn-outline-warning" for="mode-ow">بازنویسی همه</label>
                            </div>
                        </div>
                        <div class="col-12 col-md-2 text-md-end">
                            <x-button type="submit"><i class="bx bx-check"></i> اعمال</x-button>
                        </div>
                    </div>
                    <div class="small text-muted mt-2"><strong class="calc-count">0</strong> پکیج انتخاب شده — «فقط دوره‌های خالی» قیمت‌های تنظیم‌شده‌ی فعلی را دست نمی‌زند.</div>
                    @error('mult_3m') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
                </div>
            </x-card>
        </div>

        <x-card>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-calc-select="all">انتخاب همه</button>
                    <button type="button" class="btn btn-sm btn-outline-success" data-calc-select="active">انتخاب فعال‌ها</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-calc-select="none">لغو انتخاب</button>
                </div>
                @foreach (['active' => ['پکیج‌های فعال', $activePackages, 'success', 'فعال'], 'inactive' => ['پکیج‌های غیرفعال', $inactivePackages, 'secondary', 'غیرفعال']] as $key => $group)
                    @php [$title, $packages, $color, $badge] = $group; @endphp
                    @if ($packages->isNotEmpty())
                        <h6 class="text-muted border-bottom pb-2 mb-3 mt-3"><span class="badge bg-{{ $color }}">{{ $badge }}</span> {{ $title }} <span class="text-muted">({{ persian_digits($packages->count()) }})</span></h6>
                        <div class="table-responsive mb-2">
                            <table class="table table-sm table-hover align-middle">
                                <thead><tr><th style="width:36px;"></th><th>پکیج</th><th>۱ ماهه</th><th>۳ ماهه</th><th>۶ ماهه</th><th>۱ ساله</th></tr></thead>
                                <tbody>
                                    @foreach ($packages as $package)
                                        @php
                                            $tm = $tierMap($package);
                                            $one = $tm['1m'] ?? null;
                                            $base = $one ? (float) $one->price : 0;
                                        @endphp
                                        <tr>
                                            <td>
                                                <input class="form-check-input calc-check" type="checkbox" name="package_ids[]" value="{{ $package->id }}" data-group="{{ $key }}" @disabled($base <= 0)>
                                            </td>
                                            <td>
                                                <span class="fw-semibold">{{ $package->name }}</span> <span class="badge bg-{{ $color }} ms-1">{{ $badge }}</span>
                                                @if ($base <= 0)<div class="text-danger small">قیمت ۱ماهه ندارد</div>@endif
                                            </td>
                                            <td dir="ltr" class="text-muted">{{ $base > 0 ? format_money($base, $package->moneyCurrency()) : '—' }}</td>
                                            @foreach (['3m','6m','1y'] as $t)
                                                @php $cur = $tm[$t] ?? null; $curP = $cur ? (float) $cur->price : 0; $curOn = $cur && $cur->is_enabled; @endphp
                                                <td dir="ltr">
                                                    <strong class="calc-new" data-base="{{ $base }}" data-tier="{{ $t }}" data-symbol="{{ $package->moneyCurrency()->symbol() }}" data-decimals="{{ $package->moneyCurrency()->displayDecimals() }}">—</strong>
                                                    @if ($curP > 0 && $curOn)
                                                        <div class="small text-success">فعلی: {{ format_money($curP, $package->moneyCurrency()) }}</div>
                                                    @elseif ($cur === null)
                                                        <div class="small text-muted">— بدون ردیف —</div>
                                                    @else
                                                        <div class="small text-muted">خالی/غیرفعال</div>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endforeach
            </div>
        </x-card>
    </form>
</div>

@if ($activePackages->isEmpty() && $inactivePackages->isEmpty())
    <div class="alert alert-warning">هیچ پکیجی وجود ندارد.</div>
@endif
@endsection

@push('scripts')
<script>
(function () {
    'use strict';
    // واحد پول از خود ردیف خوانده می‌شود چون هر پکیج می‌تواند ارز متفاوتی داشته باشد.
    function fmt(n, el) {
        var symbol = (el && el.dataset.symbol) || '';
        var decimals = parseInt((el && el.dataset.decimals) || '0', 10);
        try {
            return new Intl.NumberFormat('fa-IR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(n) + ' ' + symbol;
        } catch (e) {
            return n + ' ' + symbol;
        }
    }
    function faInt(n) { try { return new Intl.NumberFormat('fa-IR').format(n); } catch (e) { return '' + n; } }
    function roundPrice(v) {
        if (v <= 0) return 0;
        var step = v >= 100000 ? 1000 : (v >= 1000 ? 100 : 50);
        return Math.round(v / step) * step;
    }

    /* ---- tab switching ---- */
    document.querySelectorAll('[data-pp-tab]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[data-pp-tab]').forEach(function (b) { b.classList.toggle('active', b === btn); });
            var name = btn.dataset.ppTab;
            document.querySelectorAll('[data-pp-pane]').forEach(function (p) {
                p.style.display = (p.dataset.ppPane === name) ? '' : 'none';
            });
        });
    });

    /* ---- adjust tab ---- */
    var adjPercent = document.getElementById('adj-percent');
    function adjFactor() {
        var p = parseFloat(adjPercent.value);
        if (isNaN(p) || p <= 0) return null;
        return document.getElementById('adj-inc').checked ? (1 + p / 100) : (1 - p / 100);
    }
    function adjPreview() {
        var f = adjFactor();
        document.querySelectorAll('.adj-new').forEach(function (el) {
            var old = parseFloat(el.dataset.old);
            if (f === null) { el.textContent = '—'; el.className = 'adj-new'; return; }
            var nw = roundPrice(old * f);
            el.textContent = fmt(nw, el);
            el.className = 'adj-new ' + (nw > old ? 'text-success' : (nw < old ? 'text-danger' : ''));
        });
    }
    function adjCount() {
        var n = document.querySelectorAll('.adj-check:checked').length;
        document.querySelectorAll('.adj-count').forEach(function (e) { e.textContent = faInt(n); });
    }
    adjPercent.addEventListener('input', adjPreview);
    document.getElementById('adj-inc').addEventListener('change', adjPreview);
    document.getElementById('adj-dec').addEventListener('change', adjPreview);
    document.querySelectorAll('.adj-check').forEach(function (c) { c.addEventListener('change', adjCount); });
    document.querySelectorAll('[data-adj-select]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var mode = btn.dataset.adjSelect;
            document.querySelectorAll('.adj-check').forEach(function (c) {
                if (mode === 'all') c.checked = true; else if (mode === 'none') c.checked = false;
                else if (mode === 'active') c.checked = (c.dataset.group === 'active');
            });
            adjCount();
        });
    });
    document.getElementById('adj-form').addEventListener('submit', function (e) {
        var n = document.querySelectorAll('.adj-check:checked').length;
        var p = parseFloat(adjPercent.value);
        if (!n) { e.preventDefault(); alert('حداقل یک پکیج را انتخاب کنید.'); return; }
        if (isNaN(p) || p <= 0) { e.preventDefault(); alert('درصد معتبر وارد کنید.'); return; }
        var dir = document.getElementById('adj-inc').checked ? 'افزایش' : 'کاهش';
        if (!confirm(dir + ' قیمت ' + faInt(n) + ' پکیج به میزان ' + p + '٪ اعمال شود؟')) e.preventDefault();
    });

    /* ---- calc tab ---- */
    var m3 = document.getElementById('m3'), m6 = document.getElementById('m6'), m12 = document.getElementById('m12');
    function mult(t) { return parseFloat(t === '3m' ? m3.value : (t === '6m' ? m6.value : m12.value)); }
    function calcPreview() {
        document.querySelectorAll('.calc-new').forEach(function (el) {
            var base = parseFloat(el.dataset.base), mm = mult(el.dataset.tier);
            if (isNaN(base) || base <= 0 || isNaN(mm) || mm <= 0) { el.textContent = '—'; return; }
            el.textContent = fmt(roundPrice(base * mm), el);
        });
    }
    function calcCount() {
        var n = document.querySelectorAll('.calc-check:checked').length;
        document.querySelectorAll('.calc-count').forEach(function (e) { e.textContent = faInt(n); });
    }
    [m3, m6, m12].forEach(function (el) { el.addEventListener('input', calcPreview); });
    document.querySelectorAll('.calc-check').forEach(function (c) { c.addEventListener('change', calcCount); });
    document.querySelectorAll('[data-calc-select]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var mode = btn.dataset.calcSelect;
            document.querySelectorAll('.calc-check').forEach(function (c) {
                if (c.disabled) return;
                if (mode === 'all') c.checked = true; else if (mode === 'none') c.checked = false;
                else if (mode === 'active') c.checked = (c.dataset.group === 'active');
            });
            calcCount();
        });
    });
    document.getElementById('calc-form').addEventListener('submit', function (e) {
        var n = document.querySelectorAll('.calc-check:checked').length;
        if (!n) { e.preventDefault(); alert('حداقل یک پکیج را انتخاب کنید.'); return; }
        var ow = document.getElementById('mode-ow').checked;
        var msg = 'قیمت ۳/۶/۱۲ ماهه‌ی ' + faInt(n) + ' پکیج ساخته و فعال شود؟' + (ow ? '\n(حالت بازنویسی: قیمت‌های فعلی هم بازنویسی می‌شوند)' : '');
        if (!confirm(msg)) e.preventDefault();
    });

    adjPreview(); adjCount(); calcPreview(); calcCount();
})();
</script>
@endpush
