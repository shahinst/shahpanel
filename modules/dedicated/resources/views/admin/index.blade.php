@extends('layouts.panel')

@section('page_title', __('dedicated::admin.menu'))

@section('panel_content')
<x-page-header :title="__('dedicated::admin.menu')" :subtitle="__('dedicated::admin.admin_intro')">
    <a href="{{ route('admin.inbound-allocations.index') }}" class="btn btn-outline-primary"><i class="bx bx-transfer-alt"></i> {{ __('inbound_resellers.menu_admin') }}</a>
</x-page-header>

<div class="panel-modern-card mb-3">
    <div class="card-body">
        <h2 class="h6">{{ __('dedicated::admin.assign') }}</h2>
        <form method="POST" action="{{ route('admin.dedicated.store') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-4">
                <label class="form-label">{{ __('dedicated::admin.agent') }}</label>
                <select name="agent_user_id" id="ded-agent" class="form-select">
                    <option value="">{{ __('dedicated::admin.new_agent_option') }}</option>
                    @foreach ($agents as $agent)
                        <option value="{{ $agent->id }}">{{ $agent->full_name ?: $agent->username }} ({{ $agent->username }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('dedicated::admin.server') }}</label>
                <select name="server_id" class="form-select" required>
                    @foreach ($freeServers as $server)
                        <option value="{{ $server->id }}">{{ $server->name }} · {{ $server->type?->value }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('dedicated::admin.meter_interface') }}</label>
                <input type="text" name="meter_interface" class="form-control" dir="ltr" placeholder="ether1">
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bx bx-plus"></i> {{ __('dedicated::admin.assign_btn') }}</button></div>
            <div class="col-12 row g-2 m-0 p-0" id="ded-new-agent">
                <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.full_name') }}</label><input name="full_name" class="form-control" value="{{ old('full_name') }}"></div>
                <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.username') }}</label><input name="username" class="form-control" dir="ltr" value="{{ old('username') }}"></div>
                <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.email') }}</label><input type="email" name="email" class="form-control" dir="ltr" value="{{ old('email') }}"></div>
                <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.phone') }}</label><input name="phone" class="form-control" dir="ltr" value="{{ old('phone') }}"></div>
                <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.password') }}</label><input type="password" name="password" class="form-control" dir="ltr" minlength="8"></div>
            </div>
        </form>
        <script>
            (function () {
                const select = document.getElementById('ded-agent');
                const box = document.getElementById('ded-new-agent');
                const sync = () => {
                    const isNew = select.value === '';
                    box.style.display = isNew ? '' : 'none';
                    box.querySelectorAll('input').forEach((input) => { input.disabled = ! isNew; });
                };
                select.addEventListener('change', sync);
                sync();
            })();
        </script>
        <p class="text-muted small mt-2 mb-0">{{ __('dedicated::admin.meter_hint') }}</p>
    </div>
</div>

<div class="panel-modern-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('dedicated::admin.agent') }}</th><th>{{ __('dedicated::admin.server') }}</th>
                <th>{{ __('dedicated::admin.meter_interface') }}</th><th>{{ __('dedicated::admin.usage_total') }}</th>
                <th>{{ __('dedicated::admin.usage_today') }}</th><th></th>
            </tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>{{ $row->agent?->username }}</td>
                        <td>{{ $row->server?->name }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.dedicated.update', $row) }}" class="d-flex gap-1">
                                @csrf @method('PUT')
                                <input type="text" name="meter_interface" value="{{ $row->meter_interface }}" class="form-control form-control-sm" dir="ltr" list="ifaces-{{ $row->id }}" style="max-width:140px">
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
                        <td class="text-end">
                            <form method="POST" action="{{ route('admin.dedicated.destroy', $row) }}" data-confirm="{{ __('dedicated::admin.unassign_confirm') }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bx bx-unlink"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">{{ __('dedicated::admin.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
