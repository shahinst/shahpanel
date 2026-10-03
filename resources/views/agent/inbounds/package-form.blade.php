@extends('layouts.panel')

@php
    $editing = $package !== null;
    $durationsByTier = $editing ? $package->durations->keyBy(fn ($d) => $d->tier->value) : collect();
@endphp

@section('page_title', $editing ? __('inbound_resellers.edit_package') : __('inbound_resellers.new_package'))

@section('panel_content')
<x-page-header :title="($editing ? __('inbound_resellers.edit_package') : __('inbound_resellers.new_package')).' — '.$allocation->label()" :subtitle="__('inbound_resellers.agent_rule')" />

<div class="panel-modern-card">
    <div class="card-body">
        <form method="POST" action="{{ $editing ? route('agent.inbounds.packages.update', $package) : route('agent.inbounds.packages.store', $allocation) }}">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div class="row">
                <x-form.group :label="__('inbound_resellers.package_name')" for="name" required>
                    <input type="text" name="name" id="name" class="form-control" required maxlength="120" value="{{ old('name', $package?->name) }}">
                </x-form.group>
                <x-form.group :label="__('inbound_resellers.data_limit')" for="data_limit_gb" :hint="__('inbound_resellers.data_limit_hint')">
                    <input type="number" step="0.01" min="0" name="data_limit_gb" id="data_limit_gb" class="form-control" dir="ltr" value="{{ old('data_limit_gb', $package?->data_limit_gb ? (float) $package->data_limit_gb : null) }}">
                </x-form.group>
                <x-form.group :label="__('inbound_resellers.device_limit')" for="sanaei_limit_ip" :hint="__('packages.sanaei_limit_ip_hint')">
                    <input type="number" min="0" max="1000" name="sanaei_limit_ip" id="sanaei_limit_ip" class="form-control" dir="ltr" value="{{ old('sanaei_limit_ip', $package?->sanaei_limit_ip) }}" placeholder="0">
                </x-form.group>
                <div class="col-md-6 mb-3 d-flex align-items-end">
                    <input type="hidden" name="is_active" value="0">
                    <label class="form-check">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input" @checked(old('is_active', $package?->is_active ?? true))>
                        <span class="form-check-label">{{ __('inbound_resellers.active') }}</span>
                    </label>
                </div>
            </div>

            <h4 class="h6 mt-2">{{ __('inbound_resellers.durations') }}</h4>
            <p class="text-muted small">{{ __('inbound_resellers.durations_hint') }}</p>
            <div class="row g-2 mb-3">
                @foreach ($tiers as $tier)
                    @php
                        $row = $durationsByTier->get($tier->value);
                        $enabled = (bool) old('durations.'.$tier->value.'.is_enabled', $row?->is_enabled ?? false);
                        $price = old('durations.'.$tier->value.'.price', $row ? (float) $row->price : null);
                    @endphp
                    <div class="col-md-4">
                        <div class="border rounded p-2 d-flex align-items-center gap-2">
                            <input type="hidden" name="durations[{{ $tier->value }}][is_enabled]" value="0">
                            <input type="checkbox" class="form-check-input m-0" name="durations[{{ $tier->value }}][is_enabled]" value="1" id="tier-{{ $tier->value }}" @checked($enabled)>
                            <label for="tier-{{ $tier->value }}" class="mb-0 flex-grow-1">{{ $tier->label() }}</label>
                            <input type="number" step="0.01" min="0" name="durations[{{ $tier->value }}][price]" class="form-control form-control-sm" dir="ltr" style="max-width: 140px;" value="{{ $price }}" placeholder="{{ $allocation->moneyCurrency()->symbol() }}">
                        </div>
                    </div>
                @endforeach
            </div>

            <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
            <a href="{{ route('agent.inbounds.index') }}" class="btn btn-light">{{ __('app.cancel') }}</a>
        </form>
    </div>
</div>
@endsection
