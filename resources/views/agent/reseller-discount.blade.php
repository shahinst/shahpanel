@extends('layouts.panel')

@section('page_title', 'قیمت فروشنده‌ها')

@section('panel_content')
@php $canEdit = $range !== null; @endphp
<div class="panel-modern-card">
    <div class="card-head">
        <h3><i class="bx bx-purchase-tag"></i> قیمت فروشنده‌های من</h3>
    </div>
    <div class="card-body">
        @if (empty($rows))
            <div class="alert alert-warning mb-0">هنوز پکیجی برای شما فعال نشده است. با ادمین هماهنگ کنید.</div>
        @else
            @if ($canEdit)
                <p class="text-muted small">
                    شما می‌توانید برای هر پکیج، قیمت فروشنده‌هایتان را تا
                    <strong dir="ltr">{{ persian_digits(rtrim(rtrim(number_format($range, 2), '0'), '.')) }}٪</strong>
                    بالاتر از قیمت خودتان تعیین کنید — <strong>همین درصد، سود شماست</strong>. بیشتر از این بازه مجاز نیست.
                </p>
            @else
                <div class="alert alert-info small">قیمت فروشنده‌های شما توسط ادمین تعیین می‌شود. در حال حاضر اجازه‌ی تغییر ندارید.</div>
            @endif

            <form method="POST" action="{{ route('agent.reseller-discount.update') }}">
                @csrf
                @method('PUT')
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>پکیج</th>
                                <th>قیمت مشتری</th>
                                <th>قیمت شما</th>
                                @if ($canEdit)
                                    <th style="width: 160px;">درصد سود شما (٪)</th>
                                @endif
                                <th>فروشنده می‌پردازد</th>
                                <th>سود شما</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $r)
                                @php $pid = $r['package']->id; @endphp
                                <tr>
                                    <td>{{ $r['package']->name }}</td>
                                    <td class="text-muted">{{ format_toman($r['retail']) }}</td>
                                    <td class="text-muted">{{ format_toman($r['agent_price']) }}</td>
                                    @if ($canEdit)
                                        <td>
                                            <input type="number" step="0.01" min="0" max="{{ $range }}" dir="ltr"
                                                   class="form-control form-control-sm rmk-input"
                                                   data-pid="{{ $pid }}" data-agent="{{ $r['agent_price'] }}"
                                                   data-retail="{{ $r['retail'] }}" data-range="{{ $range }}"
                                                   name="markups[{{ $pid }}]"
                                                   value="{{ old('markups.'.$pid, rtrim(rtrim(number_format($r['markup'], 2), '0'), '.')) }}">
                                        </td>
                                    @endif
                                    <td><strong class="rmk-price" data-pid="{{ $pid }}">{{ format_toman($r['seller_price']) }}</strong></td>
                                    <td><span class="text-success rmk-profit" data-pid="{{ $pid }}">{{ format_toman($r['profit']) }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($canEdit)
                    <x-button type="submit"><i class="bx bx-save"></i> ذخیره</x-button>
                @endif
            </form>
        @endif
    </div>
</div>
@endsection

@if (! empty($rows) && $range !== null)
@push('scripts')
<script>
(function () {
    function toman(n) { try { return new Intl.NumberFormat('fa-IR').format(Math.round(n)) + ' تومان'; } catch (e) { return Math.round(n) + ''; } }
    function roundNice(a) { if (a <= 0) return 0; var s = a >= 10000 ? 1000 : (a >= 1000 ? 100 : 50); return Math.round(a / s) * s; }
    document.querySelectorAll('.rmk-input').forEach(function (el) {
        el.addEventListener('input', function () {
            var pid = el.dataset.pid, agent = parseFloat(el.dataset.agent), retail = parseFloat(el.dataset.retail), range = parseFloat(el.dataset.range);
            var m = parseFloat(el.value);
            if (!isNaN(m) && m > range) { m = range; el.value = range; }
            var price = isNaN(m) ? agent : roundNice(agent * (1 + m / 100));
            if (price > retail) price = retail;
            var pe = document.querySelector('.rmk-price[data-pid="' + pid + '"]');
            var pr = document.querySelector('.rmk-profit[data-pid="' + pid + '"]');
            if (pe) pe.textContent = toman(price);
            if (pr) pr.textContent = toman(price - agent);
        });
    });
})();
</script>
@endpush
@endif
