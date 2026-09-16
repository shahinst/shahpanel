@extends('layouts.panel')

@section('page_title', __('ui.expiring_threshold_title'))

@section('panel_content')
@php
    use App\Support\ExpiringAccountThresholds as T;
@endphp

@include('partials.panel-page-hero', [
    'title' => __('ui.expiring_threshold_title'),
    'subtitle' => __('ui.expiring_threshold_subtitle'),
    'icon' => 'bx-time-five',
])

<x-card>
    <div class="card-body">
        <p class="text-muted small">
            {{ __('ui.expiring_threshold_intro_before') }}
            <a href="{{ route('admin.accounts.expiring') }}">{{ __('ui.expiring_accounts_title') }}</a> {{ __('ui.expiring_threshold_intro_after') }}
            {{ __('ui.expiring_rule_prefix') }} <strong>{{ __('ui.either') }}</strong> {{ __('ui.expiring_rule_days') }} <strong>{{ __('ui.or') }}</strong> {{ __('ui.expiring_rule_volume') }}
        </p>

        <form method="POST" action="{{ route('admin.automation.expiring.update') }}">
            @csrf
            @method('PUT')
            <div class="row g-3">
                <div class="col-md-5">
                    <x-form.group label="{{ __('ui.expiry_alert_days_label') }}"
                                  hint="{{ __('ui.expiry_alert_days_hint', [':min' => persian_digits(T::MIN_DAYS), ':max' => persian_digits(T::MAX_DAYS), ':default' => persian_digits(T::DEFAULT_DAYS)]) }}">
                        <input type="number" name="days" dir="ltr" class="form-control"
                               min="{{ T::MIN_DAYS }}" max="{{ T::MAX_DAYS }}" step="1" required
                               value="{{ old('days', $days) }}">
                    </x-form.group>
                    @error('days') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-5">
                    <x-form.group label="{{ __('ui.volume_alert_mb_label') }}"
                                  hint="{{ __('ui.volume_alert_mb_hint', [':min' => persian_digits(T::MIN_VOLUME_MB), ':max' => persian_digits(T::MAX_VOLUME_MB), ':default' => persian_digits(T::DEFAULT_VOLUME_MB)]) }}">
                        <input type="number" name="volume_mb" dir="ltr" class="form-control"
                               min="{{ T::MIN_VOLUME_MB }}" max="{{ T::MAX_VOLUME_MB }}" step="1" required
                               value="{{ old('volume_mb', $volumeMb) }}">
                    </x-form.group>
                    @error('volume_mb') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <x-button type="submit" class="w-100"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
                </div>
            </div>

            <p class="form-text text-muted small mb-0 mt-2">
                {{ __('ui.expiring_current_prefix') }} <strong>{{ persian_digits($days) }} {{ __('ui.days_unit') }}</strong> {{ __('ui.expiring_current_middle') }}
                <strong>{{ format_data_size($volumeMb * 1024 * 1024) }}</strong> {{ __('ui.expiring_current_suffix') }}
            </p>
        </form>
    </div>
</x-card>
@endsection
