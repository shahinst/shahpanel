<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label" for="display_name">{{ __('payment_gateways.display_name') }}</label>
        <input type="text" name="display_name" id="display_name" class="form-control"
               value="{{ old('display_name', $gateway->display_name) }}" required>
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label" for="mode">{{ __('payment_gateways.mode') }}</label>
        <select name="mode" id="mode" class="form-select" required>
            @foreach (\App\Enums\PaymentGatewayMode::cases() as $modeOption)
                <option value="{{ $modeOption->value }}" @selected(old('mode', $gateway->mode->value) === $modeOption->value)>
                    {{ $modeOption->label() }}
                </option>
            @endforeach
        </select>
    </div>
</div>

<div class="mb-3">
    <input type="hidden" name="is_enabled" value="0">
    <div class="form-check">
        <input type="checkbox" class="form-check-input" name="is_enabled" id="is_enabled" value="1"
               @checked((bool) old('is_enabled', $gateway->is_enabled))>
        <label class="form-check-label" for="is_enabled">{{ __('payment_gateways.enabled') }}</label>
    </div>
</div>

<hr class="my-4">

<h4 class="h6 mb-3">{{ __('payment_gateways.commission') }}</h4>
<p class="text-muted small">{{ __('payment_gateways.commission_hint') }}</p>

<div class="row">
    <div class="col-md-4 mb-3">
        <label class="form-label" for="commission_percent">{{ __('payment_gateways.commission_percent') }}</label>
        <input type="number" step="0.01" min="0" max="100" name="commission_percent" id="commission_percent"
               class="form-control" dir="ltr"
               value="{{ old('commission_percent', $gateway->commission_percent) }}">
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label" for="commission_fixed">{{ __('payment_gateways.commission_fixed') }}</label>
        <input type="number" step="1" min="0" name="commission_fixed" id="commission_fixed"
               class="form-control" dir="ltr"
               value="{{ old('commission_fixed', $gateway->commission_fixed) }}">
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label" for="commission_payer">{{ __('payment_gateways.commission_payer') }}</label>
        <select name="commission_payer" id="commission_payer" class="form-select" required>
            @foreach (\App\Enums\CommissionPayer::cases() as $payerOption)
                <option value="{{ $payerOption->value }}" @selected(old('commission_payer', $gateway->commission_payer->value) === $payerOption->value)>
                    {{ $payerOption->label() }}
                </option>
            @endforeach
        </select>
    </div>
</div>
