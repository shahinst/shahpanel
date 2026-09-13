@extends('layouts.panel')

@section('page_title', __('tunneling.locations'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('tunneling.locations'),
    'subtitle' => __('tunneling.subtitle'),
    'icon' => 'bx-map',
    'actions' => '<a href="'.route('admin.tunneling.index').'" class="btn btn-light"><i class="bx bx-git-branch"></i> '.e(__('tunneling.groups')).'</a>',
])

<div class="row">
    <div class="col-lg-8">
        <div class="panel-modern-card mb-3">
            <div class="card-head"><h3>{{ __('tunneling.locations') }}</h3></div>
            <div class="card-body">
                <x-table :headers="[__('tunneling.name'), __('tunneling.code'), __('tunneling.iran_server'), __('tunneling.groups'), __('tunneling.managed_interfaces'), __('app.status'), __('app.actions')]">
                    @forelse ($locations as $location)
                        <tr>
                            <td><strong>{{ $location->name }}</strong></td>
                            <td><code>{{ $location->code }}</code></td>
                            <td>{{ $location->iranServer?->name ?? '—' }}</td>
                            <td>{{ persian_digits($location->tunnel_groups_count) }}</td>
                            <td>{{ persian_digits($location->managed_interfaces_count) }}</td>
                            <td>
                                <span class="badge bg-{{ $location->is_active ? 'success' : 'secondary' }}">
                                    {{ $location->is_active ? __('accounts.status_active') : __('accounts.status_disabled') }}
                                </span>
                            </td>
                            <td class="text-nowrap">
                                <button class="btn btn-sm btn-light" type="button"
                                        onclick="document.getElementById('loc-edit-{{ $location->id }}').classList.toggle('d-none')">
                                    <i class="bx bx-edit"></i> {{ __('app.edit') }}
                                </button>
                                <form method="POST" action="{{ route('admin.tunneling.locations.destroy', $location) }}" class="d-inline"
                                      onsubmit="return confirm(@js(__('app.delete').'?'))">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger"><i class="bx bx-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <tr id="loc-edit-{{ $location->id }}" class="d-none">
                            <td colspan="7">
                                <form method="POST" action="{{ route('admin.tunneling.locations.update', $location) }}" class="d-flex flex-wrap gap-2 align-items-end">
                                    @csrf @method('PUT')
                                    <div>
                                        <label class="form-label small mb-0">{{ __('tunneling.name') }}</label>
                                        <input name="name" value="{{ $location->name }}" required class="form-control form-control-sm">
                                    </div>
                                    <div>
                                        <label class="form-label small mb-0">{{ __('tunneling.code') }}</label>
                                        <input name="code" value="{{ $location->code }}" required class="form-control form-control-sm" dir="ltr">
                                    </div>
                                    <div>
                                        <label class="form-label small mb-0">{{ __('tunneling.iran_server') }}</label>
                                        <select name="iran_server_id" class="form-select form-select-sm">
                                            <option value="">—</option>
                                            @foreach ($servers as $server)
                                                <option value="{{ $server->id }}" @selected($location->iran_server_id === $server->id)>{{ $server->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="form-check mb-1">
                                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="loc-active-{{ $location->id }}" @checked($location->is_active)>
                                        <label class="form-check-label" for="loc-active-{{ $location->id }}">{{ __('tunneling.is_active') }}</label>
                                    </div>
                                    <button class="btn btn-sm btn-primary">{{ __('app.save') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">{{ __('tunneling.no_locations') }}</td></tr>
                    @endforelse
                </x-table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="panel-modern-card mb-3">
            <div class="card-head"><h3>{{ __('tunneling.create_location') }}</h3></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.tunneling.locations.store') }}">
                    @csrf
                    <x-form.group :label="__('tunneling.name')">
                        <input name="name" value="{{ old('name') }}" required class="form-control" placeholder="ترکیه">
                    </x-form.group>
                    <x-form.group :label="__('tunneling.code')">
                        <input name="code" value="{{ old('code') }}" required class="form-control" dir="ltr" placeholder="tr">
                    </x-form.group>
                    <x-form.group :label="__('tunneling.iran_server')">
                        <select name="iran_server_id" class="form-select">
                            <option value="">—</option>
                            @foreach ($servers as $server)
                                <option value="{{ $server->id }}" @selected((int) old('iran_server_id') === $server->id)>{{ $server->name }}</option>
                            @endforeach
                        </select>
                    </x-form.group>
                    <x-form.checkbox name="is_active" :label="__('tunneling.is_active')" :checked="old('is_active', true)" />
                    <button class="btn btn-primary mt-2"><i class="bx bx-plus"></i> {{ __('tunneling.create_location') }}</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
