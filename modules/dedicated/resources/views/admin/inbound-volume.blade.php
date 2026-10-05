@extends('layouts.panel')

@section('page_title', __('dedicated::admin.volume_and_requests'))

@section('panel_content')
    <x-page-header :title="__('dedicated::admin.volume_and_requests')">
        <p class="text-muted mb-0">{{ __('dedicated::admin.volume_page_intro') }}</p>
        <x-slot:actions>
            <x-button :href="route('admin.inbound-agents.index')" variant="outline-secondary"><i class="bx bx-arrow-back"></i> {{ __('app.back') }}</x-button>
        </x-slot:actions>
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
