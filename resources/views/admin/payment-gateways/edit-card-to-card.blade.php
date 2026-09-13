@extends('layouts.panel')

@section('page_title', __('payment_gateways.card_to_card_settings'))

@section('panel_content')
<x-page-header :title="$gateway->display_name" :subtitle="__('payment_gateways.card_to_card_settings')" />

<div class="row">
    <div class="col-lg-10">
        <div class="panel-modern-card">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.payment-gateways.update', $gateway->driver->value) }}">
                    @csrf
                    @method('PUT')
                    @include('admin.payment-gateways._form-common')

                    <hr class="my-4">

                    <div class="mb-3">
                        <label class="form-label" for="card_number">{{ __('payment_gateways.destination_card_number') }}</label>
                        <input type="text" name="card_number" id="card_number" class="form-control" dir="ltr" required
                               value="{{ old('card_number', $gateway->configValue('card_number')) }}">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="card_holder">{{ __('payment_gateways.card_holder') }}</label>
                        <input type="text" name="card_holder" id="card_holder" class="form-control" required
                               value="{{ old('card_holder', $gateway->configValue('card_holder')) }}">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="bank_name">{{ __('payment_gateways.bank_name') }}</label>
                        <input type="text" name="bank_name" id="bank_name" class="form-control"
                               value="{{ old('bank_name', $gateway->configValue('bank_name')) }}">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="instructions">{{ __('payment_gateways.card_instructions') }}</label>
                        <textarea name="instructions" id="instructions" class="form-control" rows="3">{{ old('instructions', $gateway->configValue('instructions')) }}</textarea>
                        <p class="text-muted small mt-1 mb-0">{{ __('payment_gateways.card_instructions_hint') }}</p>
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
