@extends('layouts.panel')

@section('page_title', __('ui.seller_pricing_page_title'))

@section('panel_content')
@php $canEdit = $range !== null; @endphp
<div class="panel-modern-card">
    <div class="card-head">
        <h3><i class="bx bx-purchase-tag"></i> {{ __('ui.my_seller_pricing') }}</h3>
    </div>
    <div class="card-body">
        @if (empty($rows))
            <div class="alert alert-warning mb-0">{{ __('ui.no_packages_enabled') }}</div>
        @else
            @if ($canEdit)
                <p class="text-muted small">
                    {{ __('ui.seller_pricing_intro_before') }}
                    <strong dir="ltr">{{ persian_digits(rtrim(rtrim(number_format($range, 2), '0'), '.')) }}٪</strong>
                    {{ __('ui.seller_pricing_intro_middle') }} <strong>{{ __('ui.seller_pricing_intro_strong') }}</strong>{{ __('ui.seller_pricing_intro_after') }}
                </p>
            @else
                <div class="alert alert-info small">{{ __('ui.seller_pricing_locked') }}</div>
            @endif

            <form method="POST" action="{{ route('agent.reseller-discount.update') }}">
                @csrf
                @method('PUT')
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>{{ __('accounts.package') }}</th>
                                <th>{{ __('ui.col_client_price') }}</th>
                                <th>{{ __('ui.col_your_price') }}</th>
                                @if ($canEdit)
                                    <th style="width: 160px;">{{ __('ui.col_your_margin_percent') }}</th>
                                @endif
                                <th>{{ __('ui.col_seller_pays') }}</th>
                                <th>{{ __('ui.col_your_profit') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $r)
                                @php
                                    $pid = $r['package']->id;
                                    // همه‌ی مبالغ این ردیف از همان پکیج می‌آیند، پس ارز پکیج ملاک است.
                                    $rowCurrency = $r['package']->moneyCurrency();
                                @endphp
                                <tr>
                                    <td>{{ $r['package']->name }}</td>
                                    <td class="text-muted">{{ format_money($r['retail'], $rowCurrency) }}</td>
                                    <td class="text-muted">{{ format_money($r['agent_price'], $rowCurrency) }}</td>
                                    @if ($canEdit)
                                        <td>
                                            <input type="number" step="0.01" min="0" max="{{ $range }}" dir="ltr"
                                                   class="form-control form-control-sm rmk-input"
                                                   data-pid="{{ $pid }}" data-agent="{{ $r['agent_price'] }}"
                                                   data-retail="{{ $r['retail'] }}" data-range="{{ $range }}"
                                                   data-currency-symbol="{{ $rowCurrency->symbol() }}"
                                                   data-currency-decimals="{{ $rowCurrency->displayDecimals() }}"
                                                   name="markups[{{ $pid }}]"
                                                   value="{{ old('markups.'.$pid, rtrim(rtrim(number_format($r['markup'], 2), '0'), '.')) }}">
                                        </td>
                                    @endif
                                    <td><strong class="rmk-price" data-pid="{{ $pid }}">{{ format_money($r['seller_price'], $rowCurrency) }}</strong></td>
                                    <td><span class="text-success rmk-profit" data-pid="{{ $pid }}">{{ format_money($r['profit'], $rowCurrency) }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($canEdit)
                    <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
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
    // واحد پول از data-attribute های همان ردیف خوانده می‌شود تا هر پکیج با ارز خودش نمایش داده شود.
    function formatMoney(n, meta) {
        var decimals = parseInt((meta && meta.currency_decimals) || '0', 10);
        if (isNaN(decimals)) decimals = 0;
        var symbol = (meta && meta.currency_symbol) || '';
        var value = decimals > 0 ? n : Math.round(n);
        try { return new Intl.NumberFormat(@json(locale_tag()), { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(value) + ' ' + symbol; }
        catch (e) { return value.toFixed(decimals) + ' ' + symbol; }
    }
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
            var meta = { currency_symbol: el.dataset.currencySymbol, currency_decimals: el.dataset.currencyDecimals };
            if (pe) pe.textContent = formatMoney(price, meta);
            if (pr) pr.textContent = formatMoney(price - agent, meta);
        });
    });
})();
</script>
@endpush
@endif
