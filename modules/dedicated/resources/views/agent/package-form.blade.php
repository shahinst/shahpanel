@extends('layouts.panel')

@php
    $editing = $package !== null;
    $byTier = $editing ? $package->durations->keyBy(fn ($d) => $d->tier->value) : collect();
    $chosenTargets = (array) old('targets', array_merge(
        (array) ($package?->mikrotik_profile_keys ?? []),
        array_map(fn ($id) => 'inbound:'.$id, (array) ($package?->sanaei_inbound_ids ?? []))
    ));
    $serverId = (int) old('server_id', $package?->default_server_id ?? $servers->first()?->id);
@endphp

@section('page_title', $editing ? __('dedicated::admin.edit_package') : __('dedicated::admin.new_package'))

@section('panel_content')
<x-page-header :title="$editing ? __('dedicated::admin.edit_package') : __('dedicated::admin.new_package')" :subtitle="__('dedicated::admin.package_rule')">
    <a href="{{ route('agent.dedicated.index') }}" class="btn btn-light"><i class="bx bx-arrow-back"></i> {{ __('app.back') }}</a>
</x-page-header>

<div class="panel-modern-card"><div class="card-body">
    <form method="POST" action="{{ $editing ? route('agent.dedicated.packages.update', $package) : route('agent.dedicated.packages.store') }}" id="ded-form">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">{{ __('dedicated::admin.package') }}</label>
                <input type="text" name="name" class="form-control" required maxlength="120" value="{{ old('name', $package?->name) }}">
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('dedicated::admin.server') }}</label>
                <select name="server_id" class="form-select" id="ded-server" required>
                    @foreach ($servers as $server)
                        <option value="{{ $server->id }}" @selected($serverId === (int) $server->id)>{{ $server->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('dedicated::admin.service_type') }}</label>
                @foreach ($servers as $server)
                    <select name="service_type" class="form-select" data-server="{{ $server->id }}" @disabled($serverId !== (int) $server->id) @if ($serverId !== (int) $server->id) hidden @endif>
                        @foreach ($types[$server->id] as $type)
                            <option value="{{ $type->value }}" @selected(old('service_type', $package?->service_type?->value) === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                @endforeach
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('dedicated::admin.data_limit') }}</label>
                <input type="number" step="0.01" min="0" name="data_limit_gb" class="form-control" dir="ltr" value="{{ old('data_limit_gb', $package?->data_limit_gb ? (float) $package->data_limit_gb : null) }}" placeholder="{{ __('dedicated::admin.unlimited') }}">
            </div>
            <div class="col-12">
                <label class="form-label">{{ __('dedicated::admin.targets') }}</label>
                <p class="text-muted small mb-1">{{ __('dedicated::admin.targets_hint') }}</p>
                @foreach ($servers as $server)
                    <div data-server="{{ $server->id }}" @if ($serverId !== (int) $server->id) hidden @endif>
                        @forelse ($targets[$server->id] as $key => $label)
                            <label class="form-check form-check-inline">
                                <input type="checkbox" class="form-check-input" name="targets[]" value="{{ $key }}" @checked(in_array($key, $chosenTargets, true)) @disabled($serverId !== (int) $server->id)>
                                <span class="form-check-label" dir="ltr">{{ $label }}</span>
                            </label>
                        @empty
                            <span class="text-muted small">{{ __('dedicated::admin.no_targets') }}</span>
                        @endforelse
                    </div>
                @endforeach
            </div>
            <div class="col-12">
                <input type="hidden" name="is_active" value="0">
                <label class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked(old('is_active', $package?->is_active ?? true))> <span class="form-check-label">{{ __('app.active') }}</span></label>
            </div>
        </div>

        <h3 class="h6 mt-3">{{ __('dedicated::admin.prices') }}</h3>
        <div class="row g-2 mb-3">
            @foreach ($tiers as $tier)
                @php $row = $byTier->get($tier->value); @endphp
                <div class="col-md-4">
                    <div class="border rounded p-2 d-flex align-items-center gap-2">
                        <input type="hidden" name="durations[{{ $tier->value }}][is_enabled]" value="0">
                        <input type="checkbox" class="form-check-input m-0" name="durations[{{ $tier->value }}][is_enabled]" value="1" id="t-{{ $tier->value }}" @checked(old('durations.'.$tier->value.'.is_enabled', $row?->is_enabled ?? false))>
                        <label for="t-{{ $tier->value }}" class="mb-0 flex-grow-1">{{ $tier->label() }}</label>
                        <input type="number" step="0.01" min="0" name="durations[{{ $tier->value }}][price]" class="form-control form-control-sm" dir="ltr" style="max-width:140px" value="{{ old('durations.'.$tier->value.'.price', $row ? (float) $row->price : null) }}">
                    </div>
                </div>
            @endforeach
        </div>

        <button class="btn btn-primary"><i class="bx bx-save"></i> {{ __('app.save') }}</button>
    </form>
</div></div>

<script>
// Show the service types and targets of the chosen server only. Hidden ones
// are also disabled so the form never sends another server's values.
document.getElementById('ded-server').addEventListener('change', function () {
    document.querySelectorAll('#ded-form [data-server]').forEach((el) => {
        const on = el.dataset.server === this.value;
        el.hidden = ! on;
        (el.matches('select') ? [el] : el.querySelectorAll('input')).forEach((i) => { i.disabled = ! on; });
    });
});
</script>
@endsection
