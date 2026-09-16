@php
    $assignablePackages = $assignablePackages ?? collect();
    $packageGroups = $packageGroups ?? collect();
    if ($packageGroups->isEmpty() && $assignablePackages->isNotEmpty()) {
        $packageGroups = app(\App\Services\PackageCategoryService::class)->groupPackages($assignablePackages);
    }
    $selectedPackageIds = collect(old('package_ids', $selectedPackageIds ?? []))->map(fn ($id) => (int) $id)->all();
    $wholesalePrices = $wholesalePrices ?? [];
    $parentWholesalePrices = $parentWholesalePrices ?? [];
    $pricingTargetRole = $pricingTargetRole ?? 'seller';
    $isAgentPricing = $pricingTargetRole === 'agent';
    $sellerMarkupApplies = ($sellerMarkupApplies ?? false) && ! $isAgentPricing && ! discount_pricing_enabled();
    $sellerMarkupPercent = $sellerMarkupPercent ?? 0;
    $markupService = $markupService ?? app(\App\Services\AgentSellerMarkupService::class);
    $packageDiscounts = $packageDiscounts ?? collect();
    $defaultAgentDiscount = $defaultAgentDiscount ?? null;
    $defaultSellerDiscount = $defaultSellerDiscount ?? null;
    $sellerPriceRange = $sellerPriceRange ?? null;
    $sellerPriceRows = $sellerPriceRows ?? collect();
@endphp

@if ($assignablePackages->isNotEmpty())
<div class="col-12 mb-3">
    <label class="form-label d-block">{{ __('packages.assigned_packages') }}</label>
    <p class="text-muted small">{{ __('packages.assigned_packages_hint') }}</p>
    @if ($sellerMarkupApplies)
        <x-alert type="info" class="py-2 small mb-2">
            {{ __('packages.seller_markup_policy_hint', ['percent' => persian_digits(number_format($sellerMarkupPercent, 2, '.', ''))]) }}
        </x-alert>
    @endif
    <p class="text-muted small">{{ $isAgentPricing ? __('packages.agent_wholesale_hint') : ($sellerMarkupApplies ? __('packages.seller_wholesale_markup_hint') : __('packages.seller_wholesale_hint')) }}</p>

    @foreach ($packageGroups as $group)
        <div class="mb-4">
            <h6 class="text-muted border-bottom pb-2 mb-3">{{ $group['label'] }}</h6>

            @foreach ($group['packages'] as $package)
                @php
                    $checked = in_array((int) $package->id, $selectedPackageIds, true)
                        || collect(old('package_ids', []))->contains((string) $package->id);
                    // تمام مبالغ این کارت مربوط به همین پکیج است، پس ارز پکیج ملاک نمایش است.
                    $packageCurrency = $package->moneyCurrency();
                @endphp
                <div class="card mb-3 border">
                    <div class="card-body py-3">
                        <div class="form-check mb-2">
                            <input class="form-check-input package-assign-toggle" type="checkbox" name="package_ids[]"
                                   value="{{ $package->id }}" id="package-assign-{{ $package->id }}"
                                   data-package-id="{{ $package->id }}"
                                   @checked($checked)>
                            <label class="form-check-label fw-semibold" for="package-assign-{{ $package->id }}">
                                {{ $package->name }}
                                <span class="text-muted small">({{ $package->service_type->label() }})</span>
                            </label>
                        </div>

                        <div class="package-pricing-table ms-4 @unless($checked) d-none @endunless" id="package-pricing-{{ $package->id }}">
                            @if (discount_pricing_enabled())
                                @if ($isAgentPricing)
                                    @php
                                        $od = $packageDiscounts[$package->id] ?? null;
                                        $retailUnit = (float) optional($package->durations->first())->price;
                                        $aVal = old("package_discounts.{$package->id}.agent", $od->discount_percent ?? $defaultAgentDiscount);
                                        $sVal = old("package_discounts.{$package->id}.seller", $od->seller_discount_percent ?? $defaultSellerDiscount);
                                        $fmt = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
                                    @endphp
                                    <div class="row g-2 align-items-end">
                                        <div class="col-6 col-md-3">
                                            <label class="form-label small mb-1">{{ __('ui.agent_discount_percent') }}</label>
                                            <input type="number" step="0.01" min="0" max="100" dir="ltr"
                                                   class="form-control form-control-sm rpd-agent" data-pkg="{{ $package->id }}" data-retail="{{ $retailUnit }}"
                                                   data-currency-symbol="{{ $packageCurrency->symbol() }}"
                                                   data-currency-decimals="{{ $packageCurrency->displayDecimals() }}"
                                                   name="package_discounts[{{ $package->id }}][agent]" value="{{ $fmt($aVal) }}">
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <label class="form-label small mb-1">{{ __('ui.seller_discount_percent') }}</label>
                                            <input type="number" step="0.01" min="0" max="100" dir="ltr"
                                                   class="form-control form-control-sm rpd-seller" data-pkg="{{ $package->id }}" data-retail="{{ $retailUnit }}"
                                                   name="package_discounts[{{ $package->id }}][seller]" value="{{ $fmt($sVal) }}">
                                        </div>
                                        <div class="col-md-6">
                                            <div class="small text-muted">
                                                {{ __('ui.retail_label') }}: <strong>{{ format_money($retailUnit, $packageCurrency) }}</strong> —
                                                {{ __('roles.agent') }}: <strong class="rpd-ap" data-pkg="{{ $package->id }}">—</strong>،
                                                {{ __('roles.seller') }}: <strong class="rpd-sp" data-pkg="{{ $package->id }}">—</strong>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="form-text small text-muted mb-0">{{ __('ui.package_discount_hint') }}</p>
                                @else
                                    @php $spRow = $sellerPriceRows[$package->id] ?? null; @endphp
                                    @if ($sellerPriceRange !== null && $spRow)
                                        <div class="row g-2 align-items-end">
                                            <div class="col-12 col-md-4">
                                                <label class="form-label small mb-1">{{ __('ui.this_seller_price_label', [':currency' => $packageCurrency->label()]) }}</label>
                                                <input type="number" step="1" min="{{ (int) round($spRow['floor']) }}" max="{{ (int) round($spRow['ceil']) }}" dir="ltr"
                                                       class="form-control form-control-sm rsp-input" data-pkg="{{ $package->id }}"
                                                       data-currency-symbol="{{ $packageCurrency->symbol() }}"
                                                       data-currency-decimals="{{ $packageCurrency->displayDecimals() }}"
                                                       data-floor="{{ (int) round($spRow['floor']) }}" data-ceil="{{ (int) round($spRow['ceil']) }}"
                                                       name="seller_package_prices[{{ $package->id }}]"
                                                       value="{{ old('seller_package_prices.'.$package->id, (int) round($spRow['current'])) }}">
                                            </div>
                                            <div class="col-12 col-md-8">
                                                <div class="small text-muted">
                                                    {{ __('ui.col_your_price') }}: <strong>{{ format_money($spRow['floor'], $packageCurrency) }}</strong> —
                                                    {{ __('ui.allowed_ceiling') }} (+{{ persian_digits(rtrim(rtrim(number_format($sellerPriceRange, 2), '0'), '.')) }}٪): <strong>{{ format_money($spRow['ceil'], $packageCurrency) }}</strong> —
                                                    {{ __('ui.col_your_profit') }}: <strong class="text-success rsp-profit" data-pkg="{{ $package->id }}">{{ format_money($spRow['current'] - $spRow['floor'], $packageCurrency) }}</strong>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="form-text small text-muted mb-0">{{ __('ui.seller_price_range_hint') }}</p>
                                    @else
                                        <p class="text-muted small mb-0">{{ __('ui.seller_price_from_agent_discount') }}</p>
                                    @endif
                                @endif
                            @elseif ($package->durations->isEmpty())
                                <p class="text-muted small mb-0">{{ __('packages.duration_not_available') }}</p>
                            @else
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead>
                                            <tr>
                                                <th>{{ __('packages.duration') }}</th>
                                                @if ($isAgentPricing)
                                                    <th>{{ __('packages.catalog_price') }}</th>
                                                    <th>
                                                        {{ __('packages.agent_wholesale_price') }}
                                                        @if ($package->isElastic())
                                                            <span class="text-muted fw-normal">({{ __('packages.per_gb_short') }})</span>
                                                        @endif
                                                    </th>
                                                @else
                                                    <th>{{ __('packages.catalog_price_ceiling') }}</th>
                                                    <th>{{ __('packages.your_agent_price') }}</th>
                                                    @if ($sellerMarkupApplies)
                                                        <th>{{ __('packages.max_seller_price') }}</th>
                                                        <th>{{ __('packages.max_agent_margin') }}</th>
                                                    @endif
                                                    <th>
                                                        {{ __('packages.seller_wholesale_price') }}
                                                        @if ($package->isElastic())
                                                            <span class="text-muted fw-normal">({{ __('packages.per_gb_short') }})</span>
                                                        @endif
                                                    </th>
                                                @endif
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($package->durations as $duration)
                                                @php
                                                    $catalog = number_format((float) $duration->price, 0, '.', '');
                                                    $parentFloor = $parentWholesalePrices[$duration->id] ?? $parentWholesalePrices[(string) $duration->id] ?? null;
                                                    $markupGuide = ($sellerMarkupApplies && $parentFloor !== null)
                                                        ? $markupService->pricingGuideForAgentUnit((string) $parentFloor)
                                                        : null;
                                                    $current = old(
                                                        'package_prices.'.$package->id.'.'.$duration->id,
                                                        $wholesalePrices[$duration->id] ?? $wholesalePrices[(string) $duration->id] ?? ($isAgentPricing ? $catalog : ($parentFloor ?? ''))
                                                    );
                                                    if ($isAgentPricing) {
                                                        $minAttr = 'min="0"';
                                                        $maxAttr = '';
                                                    } else {
                                                        $minAttr = $parentFloor !== null ? 'min="'.e((string) (float) $parentFloor).'"' : 'min="0"';
                                                        if ($markupGuide !== null) {
                                                            $maxAttr = 'max="'.e((string) (float) $markupGuide['max_unit']).'"';
                                                        } else {
                                                            $maxAttr = bccomp($catalog, '0', 2) > 0 ? 'max="'.e((string) (float) $catalog).'"' : '';
                                                        }
                                                    }
                                                @endphp
                                                <tr>
                                                    <td>{{ $duration->displayLabel() }}</td>
                                                    @if ($isAgentPricing)
                                                        <td class="text-muted">{{ format_money($catalog, $packageCurrency) }}</td>
                                                    @else
                                                        <td class="text-muted">{{ format_money($catalog, $packageCurrency) }}</td>
                                                        <td class="text-muted">{{ format_money($parentFloor ?? 0, $packageCurrency) }}</td>
                                                        @if ($sellerMarkupApplies)
                                                            <td class="text-muted">{{ $markupGuide ? format_money($markupGuide['max_unit'], $packageCurrency) : '—' }}</td>
                                                            <td class="text-muted">{{ $markupGuide ? format_money($markupGuide['max_margin_unit'], $packageCurrency) : '—' }}</td>
                                                        @endif
                                                    @endif
                                                    <td style="max-width: 220px;">
                                                        <input type="number" step="1" {{ $minAttr }} {!! $maxAttr !!}
                                                               name="package_prices[{{ $package->id }}][{{ $duration->id }}]"
                                                               class="form-control form-control-sm"
                                                               value="{{ $current }}"
                                                               placeholder="{{ $isAgentPricing ? $catalog : ($parentFloor ?? '0') }}">
                                                        @unless ($isAgentPricing)
                                                            <div class="form-text small text-muted">
                                                                @if ($markupGuide !== null)
                                                                    {{ __('packages.seller_price_min_max_hint', [
                                                                        'min' => format_money($parentFloor ?? 0, $packageCurrency),
                                                                        'max' => format_money($markupGuide['max_unit'], $packageCurrency),
                                                                    ]) }}
                                                                @else
                                                                    {{ __('packages.seller_price_row_hint', [
                                                                        'base' => format_money($catalog, $packageCurrency),
                                                                        'floor' => format_money($parentFloor ?? 0, $packageCurrency),
                                                                    ]) }}
                                                                @endif
                                                            </div>
                                                        @endunless
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach
</div>

@push('scripts')
<script>
(function () {
    document.querySelectorAll('.package-assign-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            const panel = document.getElementById('package-pricing-' + checkbox.dataset.packageId);
            if (!panel) return;
            panel.classList.toggle('d-none', !checkbox.checked);
        });
    });
})();

// Live "agent/seller pays" preview for the discount grid.
(function () {
    // واحد پول از data-attribute های همان ورودی خوانده می‌شود تا هر پکیج با ارز خودش نمایش داده شود.
    function formatMoney(n, meta) {
        var decimals = parseInt((meta && meta.currency_decimals) || '0', 10);
        if (isNaN(decimals)) decimals = 0;
        var symbol = (meta && meta.currency_symbol) || '';
        var value = decimals > 0 ? n : Math.round(n);
        try { return new Intl.NumberFormat(@json(locale_tag()), { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(value) + ' ' + symbol; }
        catch (e) { return value.toFixed(decimals) + ' ' + symbol; }
    }
    function upd(pkg) {
        var a = document.querySelector('.rpd-agent[data-pkg="' + pkg + '"]');
        var s = document.querySelector('.rpd-seller[data-pkg="' + pkg + '"]');
        if (!a) return;
        var retail = parseFloat(a.dataset.retail || '0');
        var meta = { currency_symbol: a.dataset.currencySymbol, currency_decimals: a.dataset.currencyDecimals };
        var ap = document.querySelector('.rpd-ap[data-pkg="' + pkg + '"]');
        var sp = document.querySelector('.rpd-sp[data-pkg="' + pkg + '"]');
        var ad = parseFloat(a.value);
        if (ap) ap.textContent = isNaN(ad) ? '—' : formatMoney(retail * (1 - ad / 100), meta);
        var sd = s ? parseFloat(s.value) : NaN;
        if (sp) sp.textContent = isNaN(sd) ? '—' : formatMoney(retail * (1 - sd / 100), meta);
    }
    document.querySelectorAll('.rpd-agent, .rpd-seller').forEach(function (el) {
        el.addEventListener('input', function () { upd(el.dataset.pkg); });
    });
    document.querySelectorAll('.rpd-agent').forEach(function (el) { upd(el.dataset.pkg); });
})();

// Live "your profit" preview for the per-seller price inputs (markup range).
(function () {
    // واحد پول از data-attribute های همان ورودی خوانده می‌شود تا هر پکیج با ارز خودش نمایش داده شود.
    function formatMoney(n, meta) {
        var decimals = parseInt((meta && meta.currency_decimals) || '0', 10);
        if (isNaN(decimals)) decimals = 0;
        var symbol = (meta && meta.currency_symbol) || '';
        var value = decimals > 0 ? n : Math.round(n);
        try { return new Intl.NumberFormat(@json(locale_tag()), { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(value) + ' ' + symbol; }
        catch (e) { return value.toFixed(decimals) + ' ' + symbol; }
    }
    document.querySelectorAll('.rsp-input').forEach(function (el) {
        el.addEventListener('input', function () {
            var floor = parseFloat(el.dataset.floor), ceil = parseFloat(el.dataset.ceil);
            var v = parseFloat(el.value);
            if (isNaN(v)) v = floor;
            if (v < floor) v = floor;
            if (v > ceil) v = ceil;
            var p = document.querySelector('.rsp-profit[data-pkg="' + el.dataset.pkg + '"]');
            if (p) p.textContent = formatMoney(v - floor, { currency_symbol: el.dataset.currencySymbol, currency_decimals: el.dataset.currencyDecimals });
        });
    });
})();
</script>
@endpush
@else
<div class="col-12 mb-3">
    <div class="alert alert-warning mb-0">
        {{ __('packages.no_packages_for_assignment') }}
    </div>
</div>
@endif
