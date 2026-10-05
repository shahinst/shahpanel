@extends('layouts.panel')

@section('page_title', __('dedicated::admin.agent_settings'))

@section('panel_content')
<x-page-header :title="__('dedicated::admin.agent_settings').' — '.$agent->username" :subtitle="$agent->full_name">
    <x-slot:actions>
        <a href="{{ route('admin.dedicated.index') }}" class="btn btn-light"><i class="bx bx-arrow-back"></i> {{ __('app.back') }}</a>
        <a href="{{ route('admin.users.edit', $agent) }}" class="btn btn-outline-primary"><i class="bx bx-edit"></i> {{ __('dedicated::admin.edit_profile') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h6 mb-3"><i class="bx bx-server"></i> {{ __('dedicated::admin.agent_servers') }}</h2>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr>
                    <th>{{ __('dedicated::admin.server') }}</th>
                    <th>{{ __('dedicated::admin.meter_interface') }}</th>
                    <th>{{ __('dedicated::admin.usage_total') }}</th>
                    <th>{{ __('dedicated::admin.usage_today') }}</th>
                    <th>{{ __('dedicated::admin.last_read') }}</th>
                    <th></th>
                </tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row->server?->name }} <span class="text-muted small">· {{ $row->server?->type?->value }}</span></td>
                            <td>
                                <form method="POST" action="{{ route('admin.dedicated.update', $row) }}" class="d-flex gap-1">
                                    @csrf @method('PUT')
                                    <input type="text" name="meter_interface" value="{{ $row->meter_interface }}" class="form-control form-control-sm" dir="ltr" list="ifaces-{{ $row->id }}" style="max-width:150px" placeholder="ether1">
                                    <datalist id="ifaces-{{ $row->id }}">
                                        @foreach ((array) session('dedicated_interfaces.'.$row->id, []) as $name)<option value="{{ $name }}">@endforeach
                                    </datalist>
                                    <button class="btn btn-sm btn-light" title="{{ __('app.save') }}"><i class="bx bx-save"></i></button>
                                </form>
                                @if ($row->server?->isMikrotik())
                                    <form method="POST" action="{{ route('admin.dedicated.interfaces', $row) }}" class="d-inline">@csrf
                                        <button class="btn btn-link btn-sm p-0">{{ __('dedicated::admin.load_interfaces') }}</button>
                                    </form>
                                @endif
                                @if ($row->last_error)<div class="text-danger small">{{ $row->last_error }}</div>@endif
                            </td>
                            <td dir="ltr">{{ format_data_size($row->total_rx_bytes) }}</td>
                            <td dir="ltr">{{ format_data_size((int) ($today[$row->server_id] ?? 0)) }}</td>
                            <td class="small text-muted">{{ $row->last_read_at?->diffForHumans() ?? '—' }}</td>
                            <td class="text-end">
                                @if ($rows->count() > 1)
                                    <form method="POST" action="{{ route('admin.dedicated.destroy', $row) }}" data-confirm="{{ __('dedicated::admin.unassign_confirm') }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" title="{{ __('dedicated::admin.unassign') }}"><i class="bx bx-unlink"></i></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-muted small mt-2 mb-0">{{ __('dedicated::admin.meter_hint') }}</p>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h6 mb-3"><i class="bx bx-plus-circle"></i> {{ __('dedicated::admin.add_server') }}</h2>
        @if ($freeServers->isEmpty())
            <div class="text-muted">{{ __('dedicated::admin.no_free_servers') }}</div>
        @else
            <form method="POST" action="{{ route('admin.dedicated.servers.store', $agent) }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-5">
                    <label class="form-label">{{ __('dedicated::admin.server') }}</label>
                    <select name="server_id" class="form-select" required>
                        @foreach ($freeServers as $server)
                            <option value="{{ $server->id }}">{{ $server->name }} · {{ $server->type?->value }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ __('dedicated::admin.meter_interface') }}</label>
                    <input type="text" name="meter_interface" class="form-control" dir="ltr" placeholder="ether1">
                </div>
                <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bx bx-plus"></i> {{ __('dedicated::admin.assign_btn') }}</button></div>
            </form>
        @endif
    </div>
</div>
@endsection
