@php
    use App\Enums\AccountStatus;

    $defaultRenewalMode = ($account->status === AccountStatus::Exhausted || $account->isQuotaExhausted())
        ? 'add_volume'
        : 'same';

    $renewCurrency = $account->package?->moneyCurrency() ?? \App\Enums\MoneyCurrency::default();
@endphp

@extends('layouts.panel')

@section('page_title', __('accounts.renew_title'))

@section('panel_content')
<p><a href="{{ route($prefix.'.accounts.'.$account->service_type->accountCategory()->value) }}"><i class="bx bx-arrow-back"></i> {{ $account->service_type->accountCategory()->label() }}</a></p>

@php
    $renewPricingLabels = [
        'title' => __('accounts.renew_pricing_preview'),
        'wholesale' => __('accounts.renew_wholesale_total'),
        'final' => __('accounts.final_charge'),
        'margin_hint' => __('accounts.renew_agent_margin_hint'),
        'plan_discount' => __('financial_plans.plan_discount_applied', ['amount' => ':amount']),
        'discount_hint' => __('accounts.discount_applied_hint', ['percent' => ':percent']),
        'preview_failed' => __('accounts.pricing_preview_failed'),
        'confirm' => __('accounts.renew_confirm'),
    ];
    $renewPreviewUrl = route($prefix.'.accounts.renew-preview', $account);
@endphp

<x-card :title="__('accounts.renew_title').' — '.$account->remote_username">
    <p class="text-muted">{{ __('accounts.renew_hint') }}</p>
    <p><strong>{{ __('accounts.package') }}:</strong> {{ $account->package?->name }}</p>
    @if ($renewalBuyer ?? null)
        <p><strong>{{ __('accounts.renew_payer') }}:</strong> {{ $renewalBuyer->full_name }}
            <span class="text-muted small">({{ __('accounts.renew_payer_hint') }})</span>
        </p>
    @endif
    <p><strong>{{ __('accounts.renew_pricing_model') }}:</strong>
        {{ ($pricingModel ?? 'fixed') === 'per_gb' ? __('accounts.renew_pricing_per_gb') : __('accounts.renew_pricing_fixed') }}
    </p>
    @if ($renewalGb !== null)
        <p><strong>{{ __('accounts.renew_volume') }}:</strong> {{ persian_digits(rtrim(rtrim(number_format($renewalGb, 2, '.', ''), '0'), '.')) }} {{ __('accounts.gb_unit') }}</p>
    @endif

    <form method="POST" action="{{ route($prefix.'.accounts.renew', $account) }}" id="renew-form">
        @csrf

        @if ($isElastic ?? false)
            <fieldset class="mb-3">
                <legend class="h6">{{ __('accounts.renew_mode_title') }}</legend>
                <div class="form-check">
                    <label>
                        <input type="radio" name="renewal_mode" value="same" @checked(old('renewal_mode', $defaultRenewalMode) === 'same') required>
                        {{ __('accounts.renew_mode_same') }}
                        <span class="text-muted small d-block">{{ __('accounts.renew_mode_same_hint') }}</span>
                    </label>
                </div>
                <div class="form-check">
                    <label>
                        <input type="radio" name="renewal_mode" value="add_volume" @checked(old('renewal_mode', $defaultRenewalMode) === 'add_volume')>
                        {{ __('accounts.renew_mode_add') }}
                        <span class="text-muted small d-block">{{ __('accounts.renew_mode_add_hint') }}</span>
                    </label>
                </div>
                <div class="form-check">
                    <label>
                        <input type="radio" name="renewal_mode" value="upgrade_volume" @checked(old('renewal_mode') === 'upgrade_volume')>
                        {{ __('accounts.renew_mode_upgrade') }}
                        <span class="text-muted small d-block">{{ __('accounts.renew_mode_upgrade_hint') }}</span>
                    </label>
                </div>
            </fieldset>

            <div id="renew-upgrade-gb-group" class="mb-3" @if(! in_array(old('renewal_mode', $defaultRenewalMode), ['upgrade_volume', 'add_volume'], true)) hidden @endif>
                <label for="renew-data-gb" class="form-label" id="renew-data-gb-label">{{ __('accounts.renew_upgrade_gb_label') }}</label>
                <input type="number" step="0.01" min="{{ $packageMinGb ?? 1 }}" @if($packageMaxGb) max="{{ $packageMaxGb }}" @endif
                       name="data_gb" id="renew-data-gb" class="form-control" value="{{ old('data_gb') }}"
                       placeholder="{{ __('accounts.renew_upgrade_gb_hint', [
                           'min' => persian_digits(rtrim(rtrim(number_format($packageMinGb ?? 1, 2, '.', ''), '0'), '.')),
                           'max' => $packageMaxGb !== null ? persian_digits(rtrim(rtrim(number_format($packageMaxGb, 2, '.', ''), '0'), '.')) : '∞',
                       ]) }}">
                <small class="text-muted">{{ __('accounts.renew_upgrade_gb_hint', [
                    'min' => persian_digits(rtrim(rtrim(number_format($packageMinGb ?? 1, 2, '.', ''), '0'), '.')),
                    'max' => $packageMaxGb !== null ? persian_digits(rtrim(rtrim(number_format($packageMaxGb, 2, '.', ''), '0'), '.')) : '∞',
                ]) }}</small>
            </div>
        @endif

        <p class="h6 mb-2">{{ __('packages.select_duration') }}</p>
        @foreach ($durations as $duration)
            @php($quote = $duration->renewal_quote ?? null)
            <div class="form-check">
                <label>
                    <input type="radio" name="package_duration_id" value="{{ $duration->id }}" @checked((int) old('package_duration_id', $account->package_duration_id) === (int) $duration->id) required>
                    {{ $duration->displayLabel() }} —
                    <strong class="renew-duration-price" data-duration-id="{{ $duration->id }}">{{ format_money($duration->display_price, $renewCurrency) }}</strong>
                    @if (is_array($quote) && ($quote['is_per_gb'] ?? false))
                        <span class="text-muted small renew-duration-formula" data-duration-id="{{ $duration->id }}">
                            ({{ __('accounts.renew_price_formula', [
                                'unit' => format_money($quote['unit_price'], $renewCurrency),
                                'gb' => persian_digits($quote['data_gb'] ?? '1'),
                            ]) }})
                        </span>
                    @elseif (is_array($quote))
                        <span class="text-muted small renew-duration-formula" data-duration-id="{{ $duration->id }}">({{ __('accounts.renew_price_fixed_once', ['unit' => format_money($quote['unit_price'], $renewCurrency)]) }})</span>
                    @endif
                </label>
            </div>
        @endforeach

        <div id="renew-pricing-box" class="card bg-light mt-3 mb-3" hidden>
            <div class="card-body py-3">
                <h6 class="card-title mb-3">{{ $renewPricingLabels['title'] }}</h6>
                <div class="row g-2">
                    <div class="col-sm-6">
                        <span class="text-muted d-block">{{ $renewPricingLabels['wholesale'] }}</span>
                        <strong id="renew-pricing-wholesale">—</strong>
                    </div>
                    <div class="col-sm-6">
                        <span class="text-muted d-block">{{ $renewPricingLabels['final'] }}</span>
                        <strong id="renew-pricing-final">—</strong>
                    </div>
                </div>
                <p id="renew-pricing-discount" class="text-info small mb-0 mt-2" hidden></p>
                <p id="renew-pricing-margin" class="text-muted small mb-0 mt-1" hidden></p>
                <p id="renew-pricing-error" class="text-danger small mb-0 mt-2" hidden></p>
            </div>
        </div>

        <button type="submit" class="btn btn-primary" id="renew-submit">{{ __('menu.renew') }}</button>
    </form>
</x-card>
@endsection

@push('scripts')
<script>
(function () {
    const form = document.getElementById('renew-form');
    if (!form) return;

    const previewUrl = @json($renewPreviewUrl);
    const pricingLabels = @json($renewPricingLabels);
    const isElastic = @json((bool) ($isElastic ?? false));
    const currentGb = @json($renewalGb);
    const addGbLabel = @json(__('accounts.renew_add_gb_label'));
    const upgradeGbLabel = @json(__('accounts.renew_upgrade_gb_label'));
    const gbUnit = @json(__('packages.gb_unit'));
    const tomanPerGb = @json(__('packages.toman_per_gb'));
    const priceFormulaTpl = @json(__('accounts.renew_price_formula', ['unit' => ':unit', 'gb' => ':gb']));
    const fixedOnceTpl = @json(__('accounts.renew_price_fixed_once', ['unit' => ':unit']));
    let currencyMeta = {
        symbol: @json($renewCurrency->symbol()),
        label: @json($renewCurrency->label()),
        decimals: @json($renewCurrency->displayDecimals()),
        code: @json($renewCurrency->value),
    };

    const modeInputs = form.querySelectorAll('[name="renewal_mode"]');
    const gbGroup = document.getElementById('renew-upgrade-gb-group');
    const gbInput = document.getElementById('renew-data-gb');
    const pricingBox = document.getElementById('renew-pricing-box');
    const pricingWholesale = document.getElementById('renew-pricing-wholesale');
    const pricingFinal = document.getElementById('renew-pricing-final');
    const pricingDiscount = document.getElementById('renew-pricing-discount');
    const pricingMargin = document.getElementById('renew-pricing-margin');
    const pricingError = document.getElementById('renew-pricing-error');
    const submitBtn = document.getElementById('renew-submit');

    let previewRequestId = 0;
    let lastQuote = null;

    function selectedDurationId() {
        const checked = form.querySelector('[name="package_duration_id"]:checked');
        return checked ? checked.value : '';
    }

    function renewalMode() {
        const checked = form.querySelector('[name="renewal_mode"]:checked');
        return checked ? checked.value : 'same';
    }

    function formatMoney(value, meta) {
        const m = meta || currencyMeta || {};
        const decimals = Number.isFinite(Number(m.decimals)) ? Number(m.decimals) : 0;
        const symbol = m.symbol || m.label || @json(__('packages.toman'));
        const amount = Number(value);
        if (!Number.isFinite(amount)) {
            return '—';
        }
        return amount.toLocaleString('fa-IR', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }) + ' ' + symbol;
    }

    function formatToman(value) {
        return formatMoney(value, currencyMeta);
    }

    function toggleUpgradeGb() {
        if (!gbGroup) return;
        const mode = renewalMode();
        const show = isElastic && (mode === 'upgrade_volume' || mode === 'add_volume');
        gbGroup.hidden = !show;
        const labelEl = document.getElementById('renew-data-gb-label');
        if (labelEl) {
            labelEl.textContent = mode === 'add_volume' ? addGbLabel : upgradeGbLabel;
        }
        if (gbInput) {
            if (show) {
                gbInput.setAttribute('required', 'required');
            } else {
                gbInput.removeAttribute('required');
            }
        }
    }

    function showPreviewError(message) {
        if (!pricingBox) return;
        pricingBox.hidden = false;
        lastQuote = null;
        if (pricingError) {
            pricingError.hidden = false;
            pricingError.textContent = message;
        }
        if (pricingWholesale) pricingWholesale.textContent = '—';
        if (pricingFinal) pricingFinal.textContent = '—';
        if (pricingDiscount) pricingDiscount.hidden = true;
        if (pricingMargin) pricingMargin.hidden = true;
    }

    function updateDurationLabels(data) {
        const durationId = selectedDurationId();
        const priceEl = form.querySelector('.renew-duration-price[data-duration-id="' + durationId + '"]');
        const formulaEl = form.querySelector('.renew-duration-formula[data-duration-id="' + durationId + '"]');
        if (priceEl) {
            priceEl.textContent = formatToman(data.charged_total);
        }
        if (formulaEl && data.is_per_gb && data.data_gb && data.unit_price) {
            formulaEl.textContent = '(' + priceFormulaTpl
                .replace(':unit', formatMoney(data.unit_price))
                .replace(':gb', Number(data.data_gb).toLocaleString('fa-IR')) + ')';
        } else if (formulaEl && data.unit_price) {
            formulaEl.textContent = '(' + fixedOnceTpl
                .replace(':unit', formatMoney(data.unit_price)) + ')';
        }
    }

    function renderPreview(data) {
        if (!pricingBox) return;
        lastQuote = data;
        if (data.currency_symbol || data.currency) {
            currencyMeta = {
                symbol: data.currency_symbol || currencyMeta.symbol,
                label: data.currency_label || currencyMeta.label,
                decimals: Number.isFinite(Number(data.currency_decimals)) ? Number(data.currency_decimals) : currencyMeta.decimals,
                code: data.currency || currencyMeta.code,
            };
        }
        pricingBox.hidden = false;
        if (pricingError) pricingError.hidden = true;

        let wholesaleText = formatToman(data.wholesale_total);
        if (data.is_per_gb && data.data_gb && data.unit_price) {
            wholesaleText = Number(data.data_gb).toLocaleString('fa-IR') + ' ' + gbUnit + ' × '
                + formatMoney(data.unit_price) + ' = '
                + wholesaleText;
        }
        if (pricingWholesale) pricingWholesale.textContent = wholesaleText;
        if (pricingFinal) pricingFinal.textContent = formatToman(data.charged_total);

        if (pricingDiscount) {
            pricingDiscount.hidden = true;
            pricingDiscount.textContent = '';
            if (data.plan_applied && Number(data.plan_discount) > 0) {
                pricingDiscount.hidden = false;
                pricingDiscount.textContent = pricingLabels.plan_discount.replace(':amount', formatToman(data.plan_discount));
            } else if (data.discount_active && Number(data.wholesale_total) !== Number(data.charged_total)) {
                pricingDiscount.hidden = false;
                pricingDiscount.textContent = pricingLabels.discount_hint.replace(':percent', String(data.discount_percent));
            }
        }

        if (pricingMargin) {
            pricingMargin.hidden = true;
            pricingMargin.textContent = '';
            if (Number(data.agent_margin) > 0) {
                pricingMargin.hidden = false;
                pricingMargin.textContent = pricingLabels.margin_hint.replace(':amount', formatToman(data.agent_margin));
            }
        }

        updateDurationLabels(data);
    }

    function loadPreview() {
        const durationId = selectedDurationId();
        if (!durationId) {
            if (pricingBox) pricingBox.hidden = true;
            lastQuote = null;
            return;
        }

        const params = new URLSearchParams();
        params.set('package_duration_id', durationId);
        if (isElastic) {
            params.set('renewal_mode', renewalMode());
            if ((renewalMode() === 'upgrade_volume' || renewalMode() === 'add_volume') && gbInput && gbInput.value) {
                params.set('data_gb', gbInput.value);
            }
        }

        const requestId = ++previewRequestId;
        fetch(previewUrl + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.json().then(function (payload) {
                    return { ok: response.ok, payload: payload };
                });
            })
            .then(function (result) {
                if (requestId !== previewRequestId) return;
                if (!result.ok) {
                    showPreviewError(result.payload.error || pricingLabels.preview_failed);
                    return;
                }
                renderPreview(result.payload);
            })
            .catch(function () {
                if (requestId !== previewRequestId) return;
                showPreviewError(pricingLabels.preview_failed);
            });
    }

    form.querySelectorAll('[name="package_duration_id"]').forEach(function (input) {
        input.addEventListener('change', loadPreview);
    });
    modeInputs.forEach(function (input) {
        input.addEventListener('change', function () {
            toggleUpgradeGb();
            loadPreview();
        });
    });
    if (gbInput) {
        gbInput.addEventListener('input', function () {
            if (renewalMode() === 'upgrade_volume' || renewalMode() === 'add_volume') {
                loadPreview();
            }
        });
    }

    form.addEventListener('submit', function (event) {
        if (!lastQuote || !lastQuote.charged_total) {
            event.preventDefault();
            loadPreview();
            return;
        }
        const message = pricingLabels.confirm.replace(':amount', formatToman(lastQuote.charged_total));
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });

    toggleUpgradeGb();
    loadPreview();
})();
</script>
@endpush
