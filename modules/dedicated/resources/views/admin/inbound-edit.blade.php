@extends('layouts.panel')

@section('page_title', __('dedicated::admin.agent_settings'))

@section('panel_content')
    <x-page-header :title="__('dedicated::admin.agent_settings').' — '.$agent->username">
        <p class="text-muted mb-0">{{ $agent->full_name }}</p>
        <x-slot:actions>
            <x-button :href="route('admin.users.edit', $agent)" variant="outline-primary"><i class="bx bx-edit"></i> {{ __('dedicated::admin.edit_profile') }}</x-button>
            <x-button :href="route('admin.inbound-agents.index')" variant="outline-secondary"><i class="bx bx-arrow-back"></i> {{ __('app.back') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    @foreach ($rows as $row)
        @php $pct = $row->usedPercent(); @endphp
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h5 class="mb-0">
                        <i class="bx bx-transfer-alt"></i> {{ $row->label() }}
                        <span class="text-muted small">· {{ $row->server?->name }}</span>
                    </h5>
                    <div class="d-flex align-items-center gap-2">
                        @if ($row->isActive())
                            <span class="badge bg-success">{{ __('dedicated::admin.active') }}</span>
                            <form method="POST" action="{{ route('admin.inbound-allocations.suspend', $row) }}" data-confirm="{{ __('dedicated::admin.suspend_confirm') }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-warning"><i class="bx bx-pause"></i> {{ __('dedicated::admin.suspend') }}</button>
                            </form>
                        @else
                            <span class="badge bg-warning text-dark">{{ __('dedicated::admin.suspended') }}</span>
                            <form method="POST" action="{{ route('admin.inbound-allocations.resume', $row) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-success"><i class="bx bx-play"></i> {{ __('dedicated::admin.resume') }}</button>
                            </form>
                        @endif
                    </div>
                </div>

                <div class="small mb-1" dir="ltr">{{ format_data_size($row->used_bytes) }} / {{ format_data_size($row->quota_bytes) }}</div>
                <div class="progress mb-3" style="height: 8px">
                    <div class="progress-bar {{ $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success') }}" style="width: {{ min(100, $pct) }}%"></div>
                </div>

                <form method="POST" action="{{ route('admin.inbound-agents.inbounds.update', ['allocation' => $row]) }}" class="row g-3">
                    @csrf
                    @method('PUT')
                    <div class="col-md-4">
                        <label class="form-label">{{ __('dedicated::admin.title') }}</label>
                        <input name="title" class="form-control" value="{{ $row->title }}">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">{{ __('dedicated::admin.inbound') }}</label>
                        @forelse ($inbounds[$row->server_id] ?? [] as $id => $name)
                            <label class="form-check">
                                <input type="checkbox" class="form-check-input" name="inbound_ids[]" value="{{ $id }}" @checked(in_array($id, $row->inboundIdList(), true))>
                                <span class="form-check-label">{{ $name }} <span class="text-muted">#{{ $id }}</span></span>
                            </label>
                        @empty
                            <div class="small text-muted">{{ __('dedicated::admin.no_inbounds') }}</div>
                        @endforelse
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('dedicated::admin.quota_gb') }}</label>
                        <input type="number" name="quota_gb" min="0" value="{{ intdiv((int) $row->quota_bytes, \App\Models\InboundAllocation::GB) }}" class="form-control" dir="ltr" required>
                        <div class="form-text">{{ __('dedicated::admin.quota_hint') }}</div>
                    </div>
                    <div class="col-12"><button class="btn btn-primary"><i class="bx bx-save"></i> {{ __('app.save') }}</button></div>
                </form>
            </div>
        </div>
    @endforeach

    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-3"><i class="bx bx-plus-circle"></i> {{ __('dedicated::admin.add_inbound') }}</h5>
            @if ($servers->isEmpty())
                <x-alert type="info">{{ __('dedicated::admin.no_sanaei_servers') }}</x-alert>
            @else
                <form method="POST" action="{{ route('admin.inbound-agents.inbounds.store', $agent) }}" class="row g-3">
                    @csrf
                    <div class="col-md-3"><label class="form-label">{{ __('dedicated::admin.title') }}</label><input name="title" class="form-control"></div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('dedicated::admin.server') }}</label>
                        <select name="server_id" id="ia-server" class="form-select" required>
                            @foreach ($servers as $server)
                                <option value="{{ $server->id }}">{{ $server->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
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
                    <div class="col-md-2"><label class="form-label">{{ __('dedicated::admin.quota_gb') }}</label><input type="number" name="quota_gb" min="0" value="0" class="form-control" dir="ltr" required></div>
                    <div class="col-12"><button class="btn btn-outline-primary"><i class="bx bx-plus"></i> {{ __('dedicated::admin.add_inbound') }}</button></div>
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

    <div class="card">
        <div class="card-body">
            <h5 class="mb-3"><i class="bx bx-history"></i> {{ __('dedicated::admin.my_requests') }}</h5>
            @if ($requests->isEmpty())
                <div class="text-muted">{{ __('dedicated::admin.none') }}</div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr>
                            <th>{{ __('dedicated::admin.inbound') }}</th>
                            <th>{{ __('dedicated::admin.volume_pack') }}</th>
                            <th>{{ __('dedicated::admin.price') }}</th>
                            <th>{{ __('dedicated::admin.status') }}</th>
                            <th></th>
                        </tr></thead>
                        <tbody>
                            @foreach ($requests as $req)
                                <tr>
                                    <td>{{ $req->allocation?->label() }}</td>
                                    <td dir="ltr">{{ persian_digits($req->approved_gb ?? $req->requested_gb) }} GB</td>
                                    <td dir="ltr">{{ format_money($req->charged_amount ?? $req->amount, $req->currency) }}</td>
                                    <td>{{ __('dedicated::admin.status_'.$req->status) }}</td>
                                    <td class="small text-muted">{{ $req->created_at?->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
