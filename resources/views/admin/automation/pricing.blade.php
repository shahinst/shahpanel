@extends('layouts.panel')

@section('page_title', __('automation.pricing_page_title'))

@section('panel_content')
<p class="text-muted">{{ __('automation.pricing_page_hint') }}</p>

@if (session('success'))
    <x-alert type="success" class="margin-bottom">{{ session('success') }}</x-alert>
@endif

<div class="row">
    <div class="col-lg-6">
        <x-card :title="__('automation.pricing_card')">
            <p class="help-block">{{ __('automation.pricing_hint') }}</p>

            <form method="POST" action="{{ route('admin.automation.pricing.update') }}">
                @csrf
                @method('PUT')
                <div class="mb-3">
                    <label class="form-label" for="agent_seller_markup_percent">{{ __('automation.agent_seller_markup_percent') }}</label>
                    <div class="input-group" style="max-width: 14rem;">
                        <input type="number" step="0.01" min="0" max="100" class="form-control"
                               id="agent_seller_markup_percent" name="agent_seller_markup_percent"
                               value="{{ old('agent_seller_markup_percent', number_format($agentSellerMarkupPercent, 2, '.', '')) }}" required>
                        <span class="input-group-text">%</span>
                    </div>
                    <div class="form-text">{{ __('automation.agent_seller_markup_help') }}</div>
                    @error('agent_seller_markup_percent')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </div>

                @if ($agentSellerMarkupPercent > 0)
                    <x-alert type="info" class="py-2 small">
                        {{ __('automation.pricing_active_hint', ['percent' => persian_digits(number_format($agentSellerMarkupPercent, 2, '.', ''))]) }}
                    </x-alert>
                @else
                    <x-alert type="warning" class="py-2 small">
                        {{ __('automation.pricing_inactive_hint') }}
                    </x-alert>
                @endif

                <x-button type="submit">{{ __('app.save') }}</x-button>
            </form>
        </x-card>
    </div>

    <div class="col-lg-6">
        <x-card :title="__('automation.pricing_how_it_works')">
            <ul class="mb-0 ps-3">
                <li class="mb-2">{{ __('automation.pricing_step_agent') }}</li>
                <li class="mb-2">{{ __('automation.pricing_step_cap') }}</li>
                <li class="mb-2">{{ __('automation.pricing_step_margin') }}</li>
                <li>{{ __('automation.pricing_step_accounting') }}</li>
            </ul>
        </x-card>
    </div>
</div>
@endsection
