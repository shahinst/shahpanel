@extends('layouts.panel')

@section('page_title', __('inbound_resellers.agent_title'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('inbound_resellers.agent_title'),
    'subtitle' => __('inbound_resellers.agent_subtitle'),
    'icon' => 'bx-transfer-alt',
])

@if ($allocations->isEmpty())
    <div class="panel-modern-card"><div class="card-body text-center text-muted py-5">{{ __('inbound_resellers.agent_empty') }}</div></div>
@else
    <x-alert type="info" class="mb-3">{{ __('inbound_resellers.agent_rule') }}</x-alert>

    @foreach ($allocations as $allocation)
        @php
            $summary = $summaries[$allocation->id];
            $percent = $allocation->usedPercent();
            $remainingGb = $allocation->remainingBytes() / \App\Models\InboundAllocation::GB;
        @endphp
        <div class="panel-modern-card mb-4">
            <div class="card-head d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h3 class="mb-0">
                    <i class="bx bx-server"></i> {{ $allocation->label() }}
                    @if ($allocation->isActive())
                        <span class="badge bg-success">{{ __('inbound_resellers.status_active') }}</span>
                    @else
                        <span class="badge bg-danger">{{ __('inbound_resellers.status_suspended') }} — {{ __('inbound_resellers.reason_'.($allocation->suspended_reason ?? 'admin')) }}</span>
                    @endif
                </h3>
                @if ($allocation->isActive())
                    <a href="{{ route('agent.inbounds.packages.create', $allocation) }}" class="btn btn-primary btn-sm"><i class="bx bx-plus"></i> {{ __('inbound_resellers.new_package') }}</a>
                @endif
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between small mb-1">
                            <span>{{ __('inbound_resellers.used') }}: <b dir="ltr">{{ persian_digits(format_data_size($allocation->used_bytes)) }}</b></span>
                            <span class="text-muted">{{ __('inbound_resellers.quota') }}: <span dir="ltr">{{ persian_digits(format_data_size($allocation->quota_bytes)) }}</span></span>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div @class(['progress-bar', 'bg-danger' => $percent >= 90, 'bg-warning' => $percent >= 70 && $percent < 90]) style="width: {{ $percent }}%"></div>
                        </div>
                        <div class="small text-muted mt-1">{{ __('inbound_resellers.remaining') }}: <span dir="ltr">{{ persian_digits(format_data_size($allocation->remainingBytes())) }}</span> · {{ persian_digits($percent) }}%</div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="text-muted small">{{ __('inbound_resellers.price_per_gb') }}</div>
                        <strong>{{ format_money($allocation->price_per_gb, $allocation->currency) }}</strong>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="text-muted small">{{ __('inbound_resellers.billed') }}</div>
                        <strong>{{ format_money($allocation->billed_amount, $allocation->currency) }}</strong>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="text-muted small">{{ __('inbound_resellers.accounts') }}</div>
                        <strong>{{ persian_digits($summary['active_accounts']) }} / {{ persian_digits($summary['accounts']) }}</strong>
                    </div>
                    <div class="col-6 col-md-6">
                        <div class="text-muted small">{{ __('inbound_resellers.estimated_cost') }}</div>
                        <strong>{{ format_money(round($remainingGb * (float) $allocation->price_per_gb, 2), $allocation->currency) }}</strong>
                    </div>
                    <div class="col-6 col-md-6">
                        <div class="text-muted small">{{ __('inbound_resellers.last_billed') }}</div>
                        <strong>{{ $allocation->last_billed_at ? jalali_date($allocation->last_billed_at) : '—' }}</strong>
                    </div>
                </div>

                <h4 class="h6">{{ __('inbound_resellers.packages') }}</h4>
                <x-table :headers="[__('inbound_resellers.package_name'), __('inbound_resellers.data_limit'), __('inbound_resellers.durations'), __('inbound_resellers.status'), '']">
                    @forelse ($allocation->packages as $package)
                        <tr>
                            <td><strong>{{ $package->name }}</strong></td>
                            <td dir="ltr">{{ $package->data_limit_gb ? persian_digits(rtrim(rtrim((string) $package->data_limit_gb, '0'), '.')).' GB' : __('dashboard.unlimited') }}</td>
                            <td>
                                @foreach ($package->durations->where('is_enabled', true) as $duration)
                                    <span class="badge bg-light text-dark border">{{ $duration->tier->label() }}: {{ format_money($duration->price, $allocation->currency) }}</span>
                                @endforeach
                            </td>
                            <td>
                                @if ($package->is_active)
                                    <span class="badge bg-success">{{ __('inbound_resellers.status_active') }}</span>
                                @else
                                    <span class="badge bg-secondary">{{ __('app.inactive') }}</span>
                                @endif
                            </td>
                            <td class="text-nowrap">
                                <div class="icon-actions">
                                    <x-icon-action icon="bx-edit" :label="__('app.edit')" :href="route('agent.inbounds.packages.edit', $package)" />
                                    <x-icon-action icon="bx-trash" variant="danger" :label="__('app.delete')" :action="route('agent.inbounds.packages.destroy', $package)" method="DELETE" :confirm="__('inbound_resellers.delete_package_confirm')" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">{{ __('inbound_resellers.no_packages') }}</td></tr>
                    @endforelse
                </x-table>
            </div>
        </div>
    @endforeach
@endif
@endsection
