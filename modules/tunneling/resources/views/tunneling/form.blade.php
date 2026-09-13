@extends('layouts.panel')

@section('page_title', $group ? __('tunneling.edit_group') : __('tunneling.create_group'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => $group ? __('tunneling.edit_group') : __('tunneling.create_group'),
    'subtitle' => $group?->name,
    'icon' => 'bx-git-branch',
])

@php
    $selectedExits = old('exit_ids', $group?->exits?->pluck('server_id')->all() ?? []);
@endphp

<form method="POST" action="{{ $group ? route('admin.tunneling.groups.update', $group) : route('admin.tunneling.groups.store') }}">
    @csrf
    @if ($group) @method('PUT') @endif

    <div class="row">
        <div class="col-lg-6">
            <div class="panel-modern-card mb-3">
                <div class="card-head"><h3><i class="bx bx-info-circle align-middle"></i> {{ __('tunneling.group') }}</h3></div>
                <div class="card-body">
                    <x-form.group :label="__('tunneling.name')">
                        <input name="name" value="{{ old('name', $group?->name) }}" required class="form-control">
                    </x-form.group>

                    <x-form.group :label="__('tunneling.kind')">
                        <select name="kind" class="form-select">
                            @foreach (\App\Enums\TunnelKind::cases() as $kind)
                                <option value="{{ $kind->value }}" @selected(old('kind', $group?->kind?->value) === $kind->value)>{{ $kind->label() }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">{{ __('tunneling.kind_primary_hint') }}</small>
                    </x-form.group>

                    <x-form.group :label="__('tunneling.kind_mix')">
                        @php
                            $selectedMix = old('kind_mix', $group?->kindMix() ? array_map(fn ($k) => $k->value, $group->kindMix()) : [old('kind', $group?->kind?->value ?? 'gre')]);
                        @endphp
                        <div class="row g-2">
                            @foreach (\App\Enums\TunnelKind::cases() as $kind)
                                <div class="col-sm-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="kind_mix[]"
                                               id="kind-mix-{{ $kind->value }}" value="{{ $kind->value }}"
                                               @checked(in_array($kind->value, (array) $selectedMix, true))>
                                        <label class="form-check-label" for="kind-mix-{{ $kind->value }}">{{ $kind->label() }}</label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <small class="text-muted d-block mt-1">{{ __('tunneling.kind_mix_hint') }}</small>
                    </x-form.group>

                    <x-form.group :label="__('tunneling.location')">
                        <select name="location_id" class="form-select">
                            <option value="">—</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}" @selected((int) old('location_id', $group?->location_id) === $location->id)>{{ $location->name }} ({{ $location->code }})</option>
                            @endforeach
                        </select>
                    </x-form.group>

                    <x-form.group :label="__('tunneling.agents_per_exit')">
                        <input name="agents_per_exit" type="number" min="1" max="8" value="{{ old('agents_per_exit', $group?->agents_per_exit ?? 2) }}" class="form-control">
                    </x-form.group>

                    <x-form.group :label="__('tunneling.balancing_mode')">
                        <select name="balancing_mode" class="form-select">
                            <option value="pcc" @selected(old('balancing_mode', $group?->balancing_mode?->value ?? 'pcc') === 'pcc')>{{ __('tunneling.balancing_pcc') }}</option>
                            <option value="ecmp" @selected(old('balancing_mode', $group?->balancing_mode?->value) === 'ecmp')>{{ __('tunneling.balancing_ecmp') }}</option>
                            <option value="range_split" @selected(old('balancing_mode', $group?->balancing_mode?->value) === 'range_split')>{{ __('tunneling.balancing_range_split') }}</option>
                        </select>
                    </x-form.group>

                    <x-form.group :label="__('tunneling.circuit_id')">
                        <input name="circuit_id" value="{{ old('circuit_id', $group?->circuit_id) }}" class="form-control" dir="ltr">
                    </x-form.group>

                    <x-form.group :label="__('tunneling.port_hop_list')">
                        <input name="port_hop_list" value="{{ old('port_hop_list', $group?->port_hop_list) }}" class="form-control" dir="ltr" placeholder="1701,4500,13231">
                        <small class="text-muted">{{ __('tunneling.port_hop_hint') }}</small>
                    </x-form.group>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="panel-modern-card mb-3">
                <div class="card-head"><h3><i class="bx bx-server align-middle"></i> {{ __('tunneling.iran_server') }} / {{ __('tunneling.exit_servers') }}</h3></div>
                <div class="card-body">
                    <x-form.group :label="__('tunneling.iran_server')">
                        <select name="iran_server_id" class="form-select" required>
                            <option value="">—</option>
                            @foreach ($servers as $server)
                                <option value="{{ $server->id }}" @selected((int) old('iran_server_id', $group?->iran_server_id) === $server->id)>{{ $server->name }} ({{ $server->host }})</option>
                            @endforeach
                        </select>
                    </x-form.group>

                    <x-form.group :label="__('tunneling.exit_servers')">
                        @foreach ($servers as $server)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="exit_ids[]" id="exit-{{ $server->id }}"
                                       value="{{ $server->id }}" @checked(in_array($server->id, (array) $selectedExits))>
                                <label class="form-check-label" for="exit-{{ $server->id }}">{{ $server->name }} ({{ $server->host }})</label>
                            </div>
                        @endforeach
                        <small class="text-muted d-block mt-1">{{ __('tunneling.select_exits_hint') }}</small>
                    </x-form.group>
                </div>
            </div>

            <div class="panel-modern-card mb-3">
                <div class="card-head"><h3><i class="bx bx-shield-quarter align-middle"></i> {{ __('tunneling.ipsec_enabled') }} / DPI</h3></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-sm-6">
                            <x-form.checkbox name="ipsec_enabled" :label="__('tunneling.ipsec_enabled')" :checked="old('ipsec_enabled', $group?->ipsec_enabled ?? false)" />
                        </div>
                        <div class="col-sm-6">
                            <x-form.checkbox name="mss_clamp" :label="__('tunneling.mss_clamp')" :checked="old('mss_clamp', $group?->mss_clamp ?? true)" />
                        </div>
                        <div class="col-sm-6">
                            <x-form.checkbox name="auto_switch_l2tp" :label="__('tunneling.auto_switch_l2tp')" :checked="old('auto_switch_l2tp', $group?->auto_switch_l2tp ?? false)" />
                        </div>
                        <div class="col-sm-6">
                            <x-form.checkbox name="auto_switch_kind" :label="__('tunneling.auto_switch_kind')" :checked="old('auto_switch_kind', $group?->auto_switch_kind ?? false)" />
                        </div>
                    </div>
                    <small class="text-muted d-block mb-2">{{ __('tunneling.auto_switch_hint') }}</small>

                    <x-form.group :label="__('tunneling.ipsec_secret')">
                        <input name="ipsec_secret" type="password" class="form-control" dir="ltr" autocomplete="new-password">
                    </x-form.group>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-primary"><i class="bx bx-save"></i> {{ __('app.save') }}</button>
        <a href="{{ $group ? route('admin.tunneling.groups.show', $group) : route('admin.tunneling.index') }}" class="btn btn-light">{{ __('app.cancel') }}</a>
    </div>
</form>
@endsection
