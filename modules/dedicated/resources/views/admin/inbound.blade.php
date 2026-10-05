@extends('layouts.panel')

@section('page_title', __('menu.agents_inbound'))

@section('panel_content')
    <x-page-header :title="__('menu.agents_inbound')">
        <p class="text-muted mb-0">{{ __('dedicated::admin.inbound_intro') }}</p>
    </x-page-header>

    @if ($pending->isNotEmpty())
        <div class="card mb-3 border-warning">
            <div class="card-body">
                <h5 class="mb-3"><i class="bx bx-time-five"></i> {{ __('dedicated::admin.pending_requests') }} ({{ persian_digits($pending->count()) }})</h5>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>{{ __('dedicated::admin.agent') }}</th>
                                <th>{{ __('dedicated::admin.inbound') }}</th>
                                <th>{{ __('dedicated::admin.requested') }}</th>
                                <th>{{ __('dedicated::admin.give_volume') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pending as $req)
                                <tr>
                                    <td>{{ $req->agent?->full_name }}<div class="small text-muted">{{ $req->agent?->username }}</div></td>
                                    <td>{{ $req->allocation?->label() }}</td>
                                    <td>
                                        {{ persian_digits($req->requested_gb) }} GB · {{ format_money($req->amount) }}
                                        @if ($req->note)<div class="small text-muted">{{ $req->note }}</div>@endif
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.inbound-agents.requests.approve', $req) }}" class="d-flex flex-wrap gap-1 mb-1"
                                            data-confirm="{{ __('dedicated::admin.approve_confirm') }}">
                                            @csrf
                                            <input type="number" name="approved_gb" min="1" value="{{ $req->requested_gb }}" class="form-control form-control-sm" style="max-width:7rem" dir="ltr" required>
                                            <input type="text" name="admin_note" class="form-control form-control-sm" style="max-width:12rem" placeholder="{{ __('dedicated::admin.note') }}">
                                            <button class="btn btn-sm btn-success"><i class="bx bx-check"></i> {{ __('dedicated::admin.approve') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.inbound-agents.requests.reject', $req) }}" data-confirm="{{ __('dedicated::admin.reject_confirm') }}">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-danger"><i class="bx bx-x"></i> {{ __('dedicated::admin.reject') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mb-0">{{ __('dedicated::admin.approve_rule') }}</p>
            </div>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-3"><i class="bx bx-user-plus"></i> {{ __('dedicated::admin.new_inbound_agent') }}</h5>
            @if ($servers->isEmpty())
                <x-alert type="info">{{ __('dedicated::admin.no_sanaei_servers') }}</x-alert>
            @else
                <form method="POST" action="{{ route('admin.inbound-agents.store') }}" class="row g-3">
                    @csrf
                    <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.full_name') }}</label><input name="full_name" class="form-control" value="{{ old('full_name') }}" required></div>
                    <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.username') }}</label><input name="username" class="form-control" dir="ltr" value="{{ old('username') }}" required></div>
                    <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.email') }}</label><input type="email" name="email" class="form-control" dir="ltr" value="{{ old('email') }}" required></div>
                    <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.phone') }}</label><input name="phone" class="form-control" dir="ltr" value="{{ old('phone') }}"></div>
                    <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.password') }}</label><input type="password" name="password" class="form-control" dir="ltr" minlength="8" required></div>
                    <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.title') }}</label><input name="title" class="form-control" value="{{ old('title') }}"></div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('dedicated::admin.server') }}</label>
                        <select name="server_id" id="ia-server" class="form-select" required>
                            @foreach ($servers as $server)
                                <option value="{{ $server->id }}" @selected((int) old('server_id') === $server->id)>{{ $server->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">{{ __('dedicated::admin.inbound') }}</label>
                        @foreach ($servers as $server)
                            <div class="ia-inbounds" data-server="{{ $server->id }}">
                                @forelse ($inbounds[$server->id] ?? [] as $id => $name)
                                    <label class="form-check">
                                        <input type="checkbox" class="form-check-input" name="inbound_ids[]" value="{{ $id }}">
                                        <span class="form-check-label">{{ $name }} <span class="text-muted">#{{ $id }}</span></span>
                                    </label>
                                @empty
                                    <div class="small text-muted">{{ __('dedicated::admin.no_inbounds') }}</div>
                                @endforelse
                            </div>
                        @endforeach
                    </div>
                    <div class="col-md-3"><label class="form-label">{{ __('dedicated::admin.initial_volume') }}</label><input type="number" name="quota_gb" min="0" value="{{ old('quota_gb', 0) }}" class="form-control" dir="ltr" required></div>
                    <div class="col-12"><button class="btn btn-primary"><i class="bx bx-save"></i> {{ __('dedicated::admin.create_agent') }}</button></div>
                </form>
                <script>
                    (function () {
                        const select = document.getElementById('ia-server');
                        const sync = () => document.querySelectorAll('.ia-inbounds').forEach((box) => {
                            const on = box.dataset.server === select.value;
                            box.style.display = on ? '' : 'none';
                            box.querySelectorAll('input').forEach((input) => { input.disabled = ! on; });
                        });
                        select.addEventListener('change', sync);
                        sync();
                    })();
                </script>
            @endif
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-3"><i class="bx bx-transfer-alt"></i> {{ __('dedicated::admin.inbound_agents') }}</h5>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('dedicated::admin.agent') }}</th>
                            <th>{{ __('dedicated::admin.inbound') }}</th>
                            <th>{{ __('dedicated::admin.volume_use') }}</th>
                            <th>{{ __('dedicated::admin.status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($allocations as $a)
                            <tr>
                                <td>{{ $a->agent?->full_name }}<div class="small text-muted">{{ $a->agent?->username }}</div></td>
                                <td>{{ $a->label() }}<div class="small text-muted">{{ $a->server?->name }}</div></td>
                                <td style="min-width:12rem">
                                    <div class="progress" style="height:6px"><div class="progress-bar {{ $a->usedPercent() >= 90 ? 'bg-danger' : '' }}" style="width:{{ min(100, $a->usedPercent()) }}%"></div></div>
                                    <div class="small text-muted mt-1">{{ format_data_size($a->used_bytes) }} / {{ format_data_size($a->quota_bytes) }}</div>
                                </td>
                                <td>
                                    <span class="badge {{ $a->isActive() ? 'bg-success' : 'bg-danger' }}">{{ $a->isActive() ? __('dedicated::admin.active') : __('dedicated::admin.suspended') }}</span>
                                </td>
                                <td class="text-nowrap">
                                    <a href="{{ route('admin.inbound-allocations.edit', $a) }}" class="btn btn-sm btn-outline-secondary"><i class="bx bx-edit"></i></a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted text-center">{{ __('dedicated::admin.no_inbound_agents') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-3"><i class="bx bx-package"></i> {{ __('dedicated::admin.volume_packs') }}</h5>
            <p class="small text-muted">{{ __('dedicated::admin.volume_packs_intro') }}</p>
            @if ($servers->isNotEmpty())
                <form method="POST" action="{{ route('admin.inbound-agents.packs.store') }}" class="row g-2 align-items-end mb-3">
                    @csrf
                    <div class="col-md-3"><label class="form-label">{{ __('dedicated::admin.server') }}</label>
                        <select name="server_id" class="form-select">@foreach ($servers as $server)<option value="{{ $server->id }}">{{ $server->name }}</option>@endforeach</select></div>
                    <div class="col-md-3"><label class="form-label">{{ __('dedicated::admin.title') }}</label><input name="title" class="form-control" required></div>
                    <div class="col-md-2"><label class="form-label">GB</label><input type="number" name="gb" min="1" class="form-control" dir="ltr" required></div>
                    <div class="col-md-2"><label class="form-label">{{ __('dedicated::admin.price') }}</label><input type="number" name="price" min="0" class="form-control" dir="ltr" required></div>
                    <input type="hidden" name="is_active" value="1">
                    <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bx bx-plus"></i> {{ __('dedicated::admin.add') }}</button></div>
                </form>
            @endif
            <div class="table-responsive">
                <table class="table align-middle">
                    <tbody>
                        @forelse ($packs as $pack)
                            <tr>
                                <td colspan="5">
                                    <form method="POST" action="{{ route('admin.inbound-agents.packs.update', $pack) }}" class="row g-2 align-items-center">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="server_id" value="{{ $pack->server_id }}">
                                        <div class="col-md-2 small text-muted">{{ $pack->server?->name }}</div>
                                        <div class="col-md-3"><input name="title" value="{{ $pack->title }}" class="form-control form-control-sm" required></div>
                                        <div class="col-md-2"><input type="number" name="gb" value="{{ $pack->gb }}" min="1" class="form-control form-control-sm" dir="ltr" required></div>
                                        <div class="col-md-2"><input type="number" name="price" value="{{ (float) $pack->price }}" min="0" class="form-control form-control-sm" dir="ltr" required></div>
                                        <div class="col-md-1"><label class="form-check mb-0"><input type="hidden" name="is_active" value="0"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked($pack->is_active)></label></div>
                                        <div class="col-md-2 text-nowrap">
                                            <button class="btn btn-sm btn-outline-primary"><i class="bx bx-save"></i></button>
                                            <button form="del-pack-{{ $pack->id }}" class="btn btn-sm btn-outline-danger"><i class="bx bx-trash"></i></button>
                                        </div>
                                    </form>
                                    <form id="del-pack-{{ $pack->id }}" method="POST" action="{{ route('admin.inbound-agents.packs.destroy', $pack) }}" data-confirm="{{ __('dedicated::admin.delete_confirm') }}">@csrf @method('DELETE')</form>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="text-muted text-center">{{ __('dedicated::admin.no_packs') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($history->isNotEmpty())
        <div class="card">
            <div class="card-body">
                <h5 class="mb-3"><i class="bx bx-history"></i> {{ __('dedicated::admin.request_history') }}</h5>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <tbody>
                            @foreach ($history as $req)
                                <tr>
                                    <td>{{ $req->agent?->full_name }}</td>
                                    <td>{{ $req->allocation?->label() }}</td>
                                    <td>{{ persian_digits($req->approved_gb ?? $req->requested_gb) }} GB</td>
                                    <td>{{ $req->charged_amount !== null ? format_money($req->charged_amount) : '—' }}</td>
                                    <td><span class="badge {{ $req->status === 'approved' ? 'bg-success' : 'bg-secondary' }}">{{ __('dedicated::admin.status_'.$req->status) }}</span></td>
                                    <td class="small text-muted">{{ $req->reviewed_at?->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
@endsection
