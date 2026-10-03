@extends('layouts.panel')

@php $editing = $allocation->exists; @endphp

@section('page_title', $editing ? __('inbound_resellers.edit_allocation') : __('inbound_resellers.new_allocation'))

@section('panel_content')
<x-page-header :title="$editing ? __('inbound_resellers.edit_allocation') : __('inbound_resellers.new_allocation')" :subtitle="__('inbound_resellers.admin_subtitle')" />

<div class="panel-modern-card">
    <div class="card-body">
        <form method="POST" action="{{ $editing ? route('admin.inbound-allocations.update', $allocation) : route('admin.inbound-allocations.store') }}">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div class="row">
                <x-form.group :label="__('inbound_resellers.agent')" for="agent_user_id" required>
                    <select name="agent_user_id" id="agent_user_id" class="form-select" required>
                        <option value="">—</option>
                        @foreach ($agents as $agent)
                            <option value="{{ $agent->id }}" @selected((int) old('agent_user_id', $allocation->agent_user_id) === $agent->id)>{{ $agent->full_name ?: $agent->username }} ({{ $agent->username }})</option>
                        @endforeach
                    </select>
                </x-form.group>

                <x-form.group :label="__('inbound_resellers.title')" for="title">
                    <input type="text" name="title" id="title" class="form-control" value="{{ old('title', $allocation->title) }}" maxlength="120">
                </x-form.group>

                <x-form.group :label="__('inbound_resellers.server')" for="server_id" required>
                    <select name="server_id" id="server_id" class="form-select" required data-allocation-server>
                        <option value="">—</option>
                        @foreach ($servers as $server)
                            <option value="{{ $server->id }}" @selected((int) old('server_id', $allocation->server_id) === $server->id)>{{ $server->name }}</option>
                        @endforeach
                    </select>
                </x-form.group>

                <x-form.group :label="__('inbound_resellers.inbounds')" for="inbound_ids" :hint="__('inbound_resellers.inbounds_hint')" error="inbound_ids" required>
                    @php $selectedInbounds = array_map('intval', (array) old('inbound_ids', $allocation->inbound_ids ?? [])); @endphp
                    <div class="border rounded p-2" style="max-height: 220px; overflow:auto;">
                        @foreach ($inboundsByServer as $serverId => $inbounds)
                            <div data-inbounds-for="{{ $serverId }}" @if ((int) old('server_id', $allocation->server_id) !== (int) $serverId) hidden @endif>
                                @forelse ($inbounds as $inbound)
                                    <label class="d-flex align-items-center gap-2 py-1">
                                        <input type="checkbox" name="inbound_ids[]" value="{{ $inbound['id'] }}" class="form-check-input m-0" @checked(in_array($inbound['id'], $selectedInbounds, true))>
                                        <span>{{ $inbound['name'] }}</span>
                                        <span class="text-muted small" dir="ltr">#{{ $inbound['id'] }} · {{ $inbound['protocol'] }}{{ $inbound['port'] ? ' :'.$inbound['port'] : '' }}</span>
                                        @unless ($inbound['enabled'])<span class="badge bg-secondary">off</span>@endunless
                                    </label>
                                @empty
                                    <p class="text-muted small mb-0">{{ __('inbound_resellers.inbounds_empty') }}</p>
                                @endforelse
                            </div>
                        @endforeach
                    </div>
                </x-form.group>

                <x-form.group :label="__('inbound_resellers.quota')" for="quota_amount" error="quota_amount" required>
                    <div class="input-group">
                        <input type="number" step="0.01" min="1" name="quota_amount" id="quota_amount" class="form-control" dir="ltr" required value="{{ old('quota_amount', $quotaAmount) }}" placeholder="10">
                        <select name="quota_unit" class="form-select" style="max-width: 140px;">
                            <option value="tb" @selected(old('quota_unit', $quotaUnit) === 'tb')>{{ __('inbound_resellers.unit_tb') }}</option>
                            <option value="gb" @selected(old('quota_unit', $quotaUnit) === 'gb')>{{ __('inbound_resellers.unit_gb') }}</option>
                        </select>
                    </div>
                </x-form.group>

                <x-form.group :label="__('inbound_resellers.price_per_gb')" for="price_per_gb" required>
                    <div class="input-group">
                        <input type="number" step="0.01" min="0" name="price_per_gb" id="price_per_gb" class="form-control" dir="ltr" required value="{{ old('price_per_gb', $allocation->price_per_gb) }}">
                        <select name="currency" class="form-select" style="max-width: 140px;">
                            @foreach (\App\Enums\MoneyCurrency::cases() as $currency)
                                <option value="{{ $currency->value }}" @selected(old('currency', $allocation->currency) === $currency->value)>{{ $currency->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </x-form.group>

                <x-form.group :label="__('inbound_resellers.credit_limit')" for="credit_limit" :hint="__('inbound_resellers.credit_limit_hint')">
                    <input type="number" step="0.01" min="0" name="credit_limit" id="credit_limit" class="form-control" dir="ltr" value="{{ old('credit_limit', $allocation->credit_limit) }}">
                </x-form.group>

                <x-form.group :label="__('inbound_resellers.notes')" for="notes" wide>
                    <textarea name="notes" id="notes" class="form-control" rows="2">{{ old('notes', $allocation->notes) }}</textarea>
                </x-form.group>
            </div>

            <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
            <a href="{{ route('admin.inbound-allocations.index') }}" class="btn btn-light">{{ __('app.cancel') }}</a>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('change', function (event) {
    if (!event.target.matches('[data-allocation-server]')) return;
    document.querySelectorAll('[data-inbounds-for]').forEach(function (box) {
        var match = box.getAttribute('data-inbounds-for') === event.target.value;
        box.hidden = !match;
        if (!match) box.querySelectorAll('input[type=checkbox]').forEach(function (cb) { cb.checked = false; });
    });
});
</script>
@endpush
