@extends('layouts.panel')

@section('page_title', __('dedicated::admin.my_inbound'))

@php $max = max(1, $chart->max('bytes')); @endphp

@section('panel_content')
    <x-page-header :title="__('dedicated::admin.my_inbound')">
        <p class="text-muted mb-0">{{ __('dedicated::admin.my_inbound_intro') }}</p>
    </x-page-header>

    @foreach ($allocations as $a)
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <h5 class="mb-0"><i class="bx bx-transfer-alt"></i> {{ $a->label() }}</h5>
                    <span class="badge {{ $a->isActive() ? 'bg-success' : 'bg-danger' }}">{{ $a->isActive() ? __('dedicated::admin.active') : __('dedicated::admin.suspended') }}</span>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-4"><x-stat-card :title="__('dedicated::admin.volume_used')" :value="format_data_size($a->used_bytes)" icon="bx-down-arrow-alt" /></div>
                    <div class="col-md-4"><x-stat-card :title="__('dedicated::admin.volume_total')" :value="format_data_size($a->quota_bytes)" icon="bx-data" /></div>
                    <div class="col-md-4"><x-stat-card :title="__('dedicated::admin.volume_left')" :value="format_data_size($a->remainingBytes())" icon="bx-battery" /></div>
                </div>
                <div class="progress mb-3" style="height:8px"><div class="progress-bar {{ $a->usedPercent() >= 90 ? 'bg-danger' : '' }}" style="width:{{ min(100, $a->usedPercent()) }}%"></div></div>

                @php $offer = $packs[$a->server_id] ?? collect(); @endphp
                @if ($offer->isEmpty())
                    <div class="small text-muted">{{ __('dedicated::admin.no_packs_for_you') }}</div>
                @else
                    <form method="POST" action="{{ route('agent.inbound-volume.store', $a) }}" class="row g-2 align-items-end" data-confirm="{{ __('dedicated::admin.request_confirm') }}">
                        @csrf
                        <div class="col-md-5">
                            <label class="form-label">{{ __('dedicated::admin.charge_volume') }}</label>
                            <select name="pack_id" class="form-select" required>
                                @foreach ($offer as $pack)
                                    <option value="{{ $pack->id }}">{{ $pack->title }} — {{ persian_digits($pack->gb) }} GB · {{ format_money($pack->price) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.note') }}</label><input name="note" class="form-control"></div>
                        <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bx bx-send"></i> {{ __('dedicated::admin.send_request') }}</button></div>
                    </form>
                    <div class="small text-muted mt-2">{{ __('dedicated::admin.wallet_hint', ['balance' => format_money($balance)]) }}</div>
                @endif
            </div>
        </div>
    @endforeach

    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-3"><i class="bx bx-bar-chart-alt-2"></i> {{ __('dedicated::admin.usage_chart') }}</h5>
            <div class="d-flex align-items-end gap-1" style="height:9rem">
                @foreach ($chart as $point)
                    <div class="flex-fill text-center" title="{{ $point['day'] }} · {{ format_data_size($point['bytes']) }}">
                        <div class="bg-primary rounded-top mx-auto" style="width:70%;height:{{ max(2, round($point['bytes'] / $max * 130)) }}px;opacity:{{ $point['bytes'] ? 1 : .25 }}"></div>
                        <div class="small text-muted" style="font-size:.65rem">{{ persian_digits(substr($point['day'], 8)) }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    @if ($requests->isNotEmpty())
        <div class="card">
            <div class="card-body">
                <h5 class="mb-3"><i class="bx bx-history"></i> {{ __('dedicated::admin.my_requests') }}</h5>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <tbody>
                            @foreach ($requests as $req)
                                <tr>
                                    <td>{{ $req->allocation?->label() }}</td>
                                    <td>{{ persian_digits($req->approved_gb ?? $req->requested_gb) }} GB</td>
                                    <td>{{ format_money($req->charged_amount ?? $req->amount) }}</td>
                                    <td><span class="badge {{ $req->status === 'approved' ? 'bg-success' : ($req->status === 'pending' ? 'bg-warning' : 'bg-secondary') }}">{{ __('dedicated::admin.status_'.$req->status) }}</span></td>
                                    <td class="small text-muted">{{ $req->admin_note }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
@endsection
