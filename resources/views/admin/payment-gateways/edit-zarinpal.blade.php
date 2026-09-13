@extends('layouts.panel')

@section('page_title', __('payment_gateways.zarinpal_settings'))

@section('panel_content')
<x-page-header :title="$gateway->display_name" :subtitle="__('payment_gateways.zarinpal_settings')" />

<div class="row">
    <div class="col-lg-10">
        <div class="panel-modern-card mb-3">
            <div class="card-body">
                <p class="text-muted small mb-2">{{ __('payment_gateways.zarinpal_callback_url') }}</p>
                <code dir="ltr" class="d-block user-select-all">{{ route('webhooks.zarinpal') }}</code>
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
                        <label class="form-label" for="merchant_id">{{ __('payment_gateways.merchant_id') }}</label>
                        <input type="password" name="merchant_id" id="merchant_id" class="form-control" dir="ltr" autocomplete="off"
                               placeholder="{{ $gateway->hasEncryptedConfigValue('merchant_id_enc') ? __('payment_gateways.api_key_keep') : '' }}">
                        @if ($gateway->hasEncryptedConfigValue('merchant_id_enc'))
                            <p class="text-success small mt-1 mb-0"><i class="bx bx-check-circle"></i> {{ __('payment_gateways.merchant_id_saved') }}</p>
                        @endif
                    </div>

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
