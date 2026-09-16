@extends('layouts.panel')

@section('page_title', __('payment_gateways.top_up_title'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('payment_gateways.top_up_title'),
    'subtitle' => __('payment_gateways.top_up_subtitle_all'),
    'icon' => 'bx-wallet',
])

@if ($gateways->isEmpty())
    <div class="alert alert-warning">{{ __('payment_gateways.no_gateways') }}</div>
@else
    <div class="panel-form-section">
        <ul class="nav nav-tabs mb-4" id="gateway-tabs" role="tablist">
            @foreach ($gateways as $gateway)
                <li class="nav-item" role="presentation">
                    <button type="button"
                            @class(['nav-link', 'active' => ($selectedDriver ?? $gateways->first()->driver->value) === $gateway->driver->value])
                            data-gateway-tab="{{ $gateway->driver->value }}"
                            data-input-currency="{{ $gateway->driver->inputCurrency() }}">
                        {{ $gateway->display_name }}
                    </button>
                </li>
            @endforeach
        </ul>

        <form method="POST" action="{{ route($routePrefix.'.store') }}" id="gateway-top-up-form">
            @csrf
            <input type="hidden" name="driver" id="gateway-driver" value="{{ old('driver', $selectedDriver ?? $gateways->first()->driver->value) }}">

            <div id="panel-nowpayments" class="gateway-panel" data-driver="nowpayments" style="display:none">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="amount_usdt">{{ __('payment_gateways.amount_usd') }}</label>
                        <input type="number" step="0.01" min="0.01" name="amount_usdt" id="amount_usdt"
                               class="form-control amount-input" dir="ltr"
                               value="{{ old('driver') === 'nowpayments' ? old('amount_usdt') : '' }}">
                        <p class="text-muted small mt-1 mb-0">{{ __('payment_gateways.amount_usd_hint') }}</p>
                    </div>
                    @if (($nowPaymentsCurrencyOptions ?? []) !== [])
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="pay_currency">{{ __('payment_gateways.select_pay_currency') }}</label>
                            <select name="pay_currency" id="pay_currency" class="form-select" dir="ltr" required>
                                @foreach ($nowPaymentsCurrencyOptions as $option)
                                    <option value="{{ $option['code'] }}" @selected(old('pay_currency') === $option['code'])>
                                        {{ $option['label'] }} ({{ strtoupper($option['code']) }})
                                    </option>
                                @endforeach
                            </select>
                            <p class="text-muted small mt-1 mb-0">{{ __('payment_gateways.select_pay_currency_hint') }}</p>
                        </div>
                    @endif
                    @if ($usdtTomanRate)
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('payment_gateways.usdt_toman_rate') }}</label>
                            <p class="form-control-plaintext fw-semibold mb-0" dir="ltr">{{ persian_digits(number_format((float) $usdtTomanRate, 0)) }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <div id="panel-zarinpal" class="gateway-panel" data-driver="zarinpal" style="display:none">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="amount_toman_zarinpal">{{ __('payment_gateways.amount_toman') }}</label>
                        <input type="number" step="1" min="1000" name="amount_toman" id="amount_toman_zarinpal"
                               class="form-control amount-input" dir="ltr"
                               value="{{ old('driver') === 'zarinpal' ? old('amount_toman') : '' }}">
                        <p class="text-muted small mt-1 mb-0">{{ __('payment_gateways.amount_toman_hint') }}</p>
                    </div>
                </div>
            </div>

            <div id="panel-card_to_card" class="gateway-panel" data-driver="card_to_card" style="display:none">
                @php $cardGateway = $gateways->first(fn ($g) => $g->driver === \App\Enums\PaymentGatewayDriver::CardToCard); @endphp
                @if ($cardGateway)
                    <div class="alert alert-info">
                        <strong>{{ __('payment_gateways.destination_card') }}</strong>
                        <div dir="ltr" class="mt-2 user-select-all">{{ $cardGateway->configValue('card_number') }}</div>
                        <div>{{ $cardGateway->configValue('card_holder') }} @if($cardGateway->configValue('bank_name')) — {{ $cardGateway->configValue('bank_name') }} @endif</div>
                        @if ($cardGateway->configValue('instructions'))
                            <p class="small mb-0 mt-2">{{ $cardGateway->configValue('instructions') }}</p>
                        @endif
                    </div>
                @endif
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="amount_toman_card">{{ __('payment_gateways.amount_toman') }}</label>
                        <input type="number" step="1" min="1000"
                               @if(old('driver') === 'card_to_card') name="amount_toman" @endif
                               id="amount_toman_card" class="form-control amount-input" dir="ltr"
                               value="{{ old('driver') === 'card_to_card' ? old('amount_toman') : '' }}">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="tracking_number">{{ __('payment_gateways.tracking_number') }}</label>
                        <input type="text" name="tracking_number" id="tracking_number" class="form-control" dir="ltr"
                               value="{{ old('tracking_number') }}">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="card_last4">{{ __('payment_gateways.card_last4') }}</label>
                        <input type="text" name="card_last4" id="card_last4" class="form-control" dir="ltr" maxlength="4"
                               value="{{ old('card_last4') }}">
                    </div>
                    <div class="col-12 mb-3">
                        <label class="form-label" for="requester_note">{{ __('payment_gateways.requester_note') }}</label>
                        <textarea name="requester_note" id="requester_note" class="form-control" rows="2">{{ old('requester_note') }}</textarea>
                    </div>
                </div>
            </div>

            <div class="panel-modern-card mb-4" id="top-up-preview" style="display:none">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">{{ __('payment_gateways.toman_equivalent') }}</dt>
                        <dd class="col-sm-8" id="preview-gross">—</dd>
                        <dt class="col-sm-4">{{ __('payment_gateways.commission_amount') }}</dt>
                        <dd class="col-sm-8" id="preview-commission">—</dd>
                        <dt class="col-sm-4">{{ __('payment_gateways.net_credit') }}</dt>
                        <dd class="col-sm-8 fw-bold text-success" id="preview-net">—</dd>
                        <dt class="col-sm-4" id="preview-crypto-label" style="display:none">{{ __('payment_gateways.estimated_crypto') }}</dt>
                        <dd class="col-sm-8" id="preview-crypto" style="display:none">—</dd>
                    </dl>
                </div>
            </div>

            <x-form.actions>
                <x-button type="submit" id="submit-btn"><i class="bx bx-wallet"></i> <span id="submit-label">{{ __('payment_gateways.pay_now') }}</span></x-button>
            </x-form.actions>
        </form>
    </div>
@endif
@endsection

@push('scripts')
<script>
(function () {
    const previewUrl = @json(route($routePrefix.'.preview'));
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const driverInput = document.getElementById('gateway-driver');
    const previewBox = document.getElementById('top-up-preview');
    const grossEl = document.getElementById('preview-gross');
    const commissionEl = document.getElementById('preview-commission');
    const netEl = document.getElementById('preview-net');
    const cryptoEl = document.getElementById('preview-crypto');
    const cryptoLabel = document.getElementById('preview-crypto-label');
    const payCurrencySelect = document.getElementById('pay_currency');
    const submitLabel = document.getElementById('submit-label');
    const form = document.getElementById('gateway-top-up-form');
    let timer = null;

    function activePanel() {
        return document.querySelector('.gateway-panel[data-driver="' + driverInput.value + '"]');
    }

    function activeAmountInput() {
        const panel = activePanel();
        return panel ? panel.querySelector('.amount-input') : null;
    }

    function showPanel(driver) {
        driverInput.value = driver;
        document.querySelectorAll('.gateway-panel').forEach(el => {
            el.style.display = el.dataset.driver === driver ? '' : 'none';
        });
        document.querySelectorAll('[data-gateway-tab]').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.gatewayTab === driver);
        });

        document.querySelectorAll('input[name="amount_toman"]').forEach(el => el.removeAttribute('name'));
        const panel = activePanel();
        if (panel) {
            const amount = panel.querySelector('.amount-input');
            if (amount) {
                amount.setAttribute('name', driver === 'nowpayments' ? 'amount_usdt' : 'amount_toman');
            }
        }

        submitLabel.textContent = driver === 'card_to_card'
            ? @json(__('payment_gateways.submit_card_to_card'))
            : @json(__('payment_gateways.pay_now'));

        if (payCurrencySelect) {
            if (driver === 'nowpayments') {
                payCurrencySelect.setAttribute('name', 'pay_currency');
                payCurrencySelect.required = true;
            } else {
                payCurrencySelect.removeAttribute('name');
                payCurrencySelect.required = false;
            }
        }

        updatePreview();
    }

    function updatePreview() {
        const driver = driverInput.value;
        const input = activeAmountInput();
        if (!input || !previewBox) return;

        const amount = parseFloat(input.value);
        if (!amount || amount <= 0) {
            previewBox.style.display = 'none';
            return;
        }

        grossEl.textContent = @json(__('payment_gateways.preview_loading'));
        previewBox.style.display = '';

        const payload = { driver };
        if (driver === 'nowpayments') {
            payload.amount_usdt = amount;
            if (payCurrencySelect) payload.pay_currency = payCurrencySelect.value;
        } else {
            payload.amount_toman = amount;
        }

        fetch(previewUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf || '',
            },
            body: JSON.stringify(payload),
        })
            .then(r => r.json())
            .then(data => {
                grossEl.textContent = data.gross_toman_formatted + ' ' + @json(__('packages.toman'));
                commissionEl.textContent = data.commission_toman_formatted + ' ' + @json(__('packages.toman'));
                netEl.textContent = data.net_toman_formatted + ' ' + @json(__('packages.toman'));
                if (cryptoEl && cryptoLabel && data.estimated_crypto_formatted) {
                    cryptoEl.textContent = data.estimated_crypto_formatted;
                    cryptoEl.style.display = '';
                    cryptoLabel.style.display = '';
                } else if (cryptoEl && cryptoLabel) {
                    cryptoEl.style.display = 'none';
                    cryptoLabel.style.display = 'none';
                }
            })
            .catch(() => { grossEl.textContent = '—'; });
    }

    document.querySelectorAll('[data-gateway-tab]').forEach(btn => {
        btn.addEventListener('click', () => showPanel(btn.dataset.gatewayTab));
    });

    form?.querySelectorAll('.amount-input').forEach(input => {
        input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(updatePreview, 350);
        });
    });

    payCurrencySelect?.addEventListener('change', updatePreview);

    showPanel(driverInput.value || @json($gateways->first()?->driver?->value ?? 'nowpayments'));
})();
</script>
@endpush
