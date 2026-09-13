@extends('layouts.panel')

@section('page_title', __('payment_gateways.nowpayments_settings'))

@section('panel_content')
<x-page-header
    :title="$gateway->display_name"
    :subtitle="__('payment_gateways.nowpayments_settings')"
/>

<div class="row">
    <div class="col-lg-10">
        <div class="panel-modern-card mb-3">
            <div class="card-body">
                <p class="text-muted small mb-2">{{ __('payment_gateways.ipn_callback_url') }}</p>
                <code dir="ltr" class="d-block user-select-all">{{ route('webhooks.nowpayments') }}</code>
                <p class="text-muted small mt-2 mb-0">{{ __('payment_gateways.ipn_callback_hint') }}</p>
            </div>
        </div>

        <div class="panel-modern-card">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.payment-gateways.update', $gateway->driver->value) }}">
                    @csrf
                    @method('PUT')

                    @include('admin.payment-gateways._form-common')

                    <hr class="my-4">

                    <div class="mb-3">
                        <label class="form-label" for="api_key">{{ __('payment_gateways.api_key') }}</label>
                        <input type="password" name="api_key" id="api_key" class="form-control" dir="ltr" autocomplete="off"
                               placeholder="{{ $gateway->hasEncryptedConfigValue('api_key_enc') ? __('payment_gateways.api_key_keep') : '' }}">
                        @if ($gateway->hasEncryptedConfigValue('api_key_enc'))
                            <p class="text-success small mt-1 mb-0"><i class="bx bx-check-circle"></i> {{ __('payment_gateways.api_key_saved') }}</p>
                        @endif
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="ipn_secret">{{ __('payment_gateways.ipn_secret') }}</label>
                        <input type="password" name="ipn_secret" id="ipn_secret" class="form-control" dir="ltr" autocomplete="off"
                               placeholder="{{ $gateway->hasEncryptedConfigValue('ipn_secret_enc') ? __('payment_gateways.api_key_keep') : '' }}">
                        <p class="text-muted small mt-1 mb-0">{{ __('payment_gateways.ipn_secret_hint') }}</p>
                        @unless ($gateway->hasEncryptedConfigValue('ipn_secret_enc'))
                            <div class="alert alert-warning small mt-2 mb-0">{{ __('payment_gateways.ipn_secret_required_warning') }}</div>
                        @endunless
                    </div>

                    <div class="mb-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <label class="form-label mb-0">{{ __('payment_gateways.enabled_pay_currencies') }}</label>
                            @if ($gateway->hasEncryptedConfigValue('api_key_enc'))
                                <a href="{{ route('admin.payment-gateways.edit', ['driver' => $gateway->driver->value, 'refresh_currencies' => 1]) }}"
                                   class="btn btn-sm btn-outline-secondary">
                                    <i class="bx bx-refresh"></i> {{ __('payment_gateways.refresh_currencies') }}
                                </a>
                            @endif
                        </div>
                        <p class="text-muted small">{{ __('payment_gateways.enabled_pay_currencies_hint') }}</p>

                        @if (! $gateway->hasEncryptedConfigValue('api_key_enc'))
                            <div class="alert alert-warning small mb-0">{{ __('payment_gateways.currencies_need_api_key') }}</div>
                        @elseif ($currenciesError)
                            <div class="alert alert-danger small">{{ $currenciesError }}</div>
                        @elseif ($availableCurrencies === [])
                            <div class="alert alert-warning small mb-0">{{ __('payment_gateways.currencies_empty') }}</div>
                        @else
                            <input type="search" id="currency-filter" class="form-control mb-3" placeholder="{{ __('payment_gateways.filter_currencies') }}">
                            <div class="border rounded p-3" style="max-height: 320px; overflow-y: auto;" id="currency-list">
                                @foreach ($availableCurrencies as $currency)
                                    <div class="form-check currency-item" data-search="{{ strtolower($currency['name'].' '.$currency['code']) }}">
                                        <input type="checkbox" class="form-check-input"
                                               name="enabled_pay_currencies[]"
                                               id="currency_{{ $currency['code'] }}"
                                               value="{{ $currency['code'] }}"
                                               @checked(in_array($currency['code'], $selectedPayCurrencies ?? [], true))>
                                        <label class="form-check-label" for="currency_{{ $currency['code'] }}">
                                            <span dir="ltr">{{ strtoupper($currency['code']) }}</span>
                                            — {{ $currency['name'] }}
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                            <p class="text-muted small mt-2 mb-0">{{ __('payment_gateways.usd_base_rate_hint') }}</p>
                        @endif
                    </div>

                    @if ($usdtTomanRate)
                        <p class="alert alert-info small">
                            {{ __('payment_gateways.usdt_toman_rate') }}:
                            <strong>{{ persian_digits(number_format((float) $usdtTomanRate, 0)) }}</strong>
                            <a href="{{ route('admin.payment-gateways.index') }}" class="ms-2">{{ __('payment_gateways.configure') }}</a>
                        </p>
                    @else
                        <p class="alert alert-warning small">
                            {{ __('payment_gateways.usdt_rate_missing') }}
                            <a href="{{ route('admin.payment-gateways.index') }}">{{ __('payment_gateways.global_settings') }}</a>
                        </p>
                    @endif

                    <x-form.actions>
                        <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
                        <a href="{{ route('admin.payment-gateways.index') }}" class="btn btn-light">{{ __('app.back') }}</a>
                    </x-form.actions>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const filter = document.getElementById('currency-filter');
    const items = document.querySelectorAll('.currency-item');
    if (!filter || !items.length) return;

    filter.addEventListener('input', function () {
        const term = this.value.trim().toLowerCase();
        items.forEach(function (item) {
            const hay = item.dataset.search || '';
            item.style.display = !term || hay.includes(term) ? '' : 'none';
        });
    });
})();
</script>
@endpush
