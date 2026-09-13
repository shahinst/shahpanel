@extends('layouts.panel')

@section('page_title', __('kyc.title'))

@section('panel_content')
<x-page-header :title="__('kyc.title')" :subtitle="__('kyc.subtitle')" />

<div class="row">
    <div class="col-lg-8">
        <div class="panel-modern-card mb-4">
            <div class="card-head"><h3>{{ __('kyc.provider') }}</h3></div>
            <div class="card-body">
                <p class="text-muted small">{{ __('kyc.provider_hint') }}</p>

                <form method="POST" action="{{ route('admin.kyc.settings.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <input type="hidden" name="kyc_enabled" value="0">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="kyc_enabled" id="kyc_enabled" value="1"
                                   @checked((bool) old('kyc_enabled', $enabled))>
                            <label class="form-check-label" for="kyc_enabled">{{ __('kyc.enabled') }}</label>
                        </div>
                        <p class="text-muted small mt-1 mb-0">{{ __('kyc.enabled_hint') }}</p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="kyc_provider">{{ __('kyc.provider') }}</label>
                        <select name="kyc_provider" id="kyc_provider" class="form-select" required>
                            @foreach ($providers as $providerOption)
                                <option value="{{ $providerOption->value }}" @selected(old('kyc_provider', $provider->value) === $providerOption->value)>
                                    {{ $providerOption->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="kyc_api_key">{{ __('kyc.api_key') }}</label>
                        <input type="password" name="kyc_api_key" id="kyc_api_key" class="form-control" dir="ltr"
                               autocomplete="off"
                               placeholder="{{ $hasApiKey ? __('kyc.api_key_keep') : __('kyc.api_key_placeholder') }}">
                        @if ($hasApiKey)
                            <p class="text-success small mt-1 mb-0"><i class="bx bx-check-circle"></i> {{ __('kyc.api_key_saved') }}</p>
                        @endif
                        <p class="text-muted small mt-1 mb-0">{{ __('kyc.api_key_hint') }}</p>
                    </div>

                    @if ($lastTestOk !== null)
                        <div class="alert alert-light border mb-3">
                            <div class="small {{ $lastTestOk ? 'text-success' : 'text-danger' }}">
                                {{ $lastTestOk ? __('kyc.test_ok') : __('kyc.test_fail') }}
                                @if ($lastTestAt) — {{ persian_digits($lastTestAt) }} @endif
                            </div>
                        </div>
                    @endif

                    <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
                </form>
            </div>
        </div>

        <div class="panel-modern-card">
            <div class="card-head"><h3>{{ __('kyc.test_connection') }}</h3></div>
            <div class="card-body">
                <p class="text-muted small">{{ __('kyc.test_hint') }}</p>
                <form method="POST" action="{{ route('admin.kyc.settings.test') }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary" @disabled(! $hasApiKey)>
                        <i class="bx bx-plug"></i> {{ __('kyc.test_connection') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
