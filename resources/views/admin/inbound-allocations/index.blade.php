@extends('layouts.panel')

@section('page_title', __('inbound_resellers.admin_title'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('inbound_resellers.admin_title'),
    'subtitle' => __('inbound_resellers.admin_subtitle'),
    'icon' => 'bx-transfer-alt',
    'actions' => '<a href="'.route('admin.inbound-allocations.create').'" class="btn btn-light btn-sm"><i class="bx bx-plus"></i> '.e(__('inbound_resellers.new_allocation')).'</a>',
])

<x-alert type="info" class="mb-3">{{ __('inbound_resellers.how_it_works') }}</x-alert>

<div class="panel-modern-card">
    <div class="card-body">
        <x-table :headers="[__('inbound_resellers.agent'), __('inbound_resellers.server'), __('inbound_resellers.quota'), __('inbound_resellers.price_per_gb'), __('inbound_resellers.billed'), __('inbound_resellers.accounts'), __('inbound_resellers.status'), '']">
            @forelse ($allocations as $allocation)
                <tr>
                    <td>
                        <strong>{{ $allocation->agent?->full_name ?: $allocation->agent?->username }}</strong>
                        @if ($allocation->title)<div class="text-muted small">{{ $allocation->title }}</div>@endif
                    </td>
                    <td>
                        {{ $allocation->server?->name }}
                        <div class="text-muted small" dir="ltr">#{{ implode(', #', $allocation->inboundIdList()) }}</div>
                    </td>
                    <td style="min-width: 190px;">
                        <div class="d-flex justify-content-between small mb-1">
                            <span dir="ltr">{{ persian_digits(format_data_size($allocation->used_bytes)) }}</span>
                            <span class="text-muted" dir="ltr">{{ persian_digits(format_data_size($allocation->quota_bytes)) }}</span>
                        </div>
                        <div class="progress" style="height: 7px;">
                            <div @class(['progress-bar', 'bg-danger' => $allocation->usedPercent() >= 90, 'bg-warning' => $allocation->usedPercent() >= 70 && $allocation->usedPercent() < 90]) style="width: {{ $allocation->usedPercent() }}%"></div>
                        </div>
                    </td>
                    <td>{{ format_money($allocation->price_per_gb, $allocation->currency) }}</td>
                    <td>{{ format_money($allocation->billed_amount, $allocation->currency) }}</td>
                    <td>{{ persian_digits($allocation->accounts_count) }} <span class="text-muted small">/ {{ persian_digits($allocation->packages_count) }} {{ __('inbound_resellers.packages') }}</span></td>
                    <td>
                        @if ($allocation->isActive())
                            <span class="badge bg-success">{{ __('inbound_resellers.status_active') }}</span>
                        @else
                            <span class="badge bg-danger">{{ __('inbound_resellers.status_suspended') }}</span>
                            @if ($allocation->suspended_reason)<div class="text-muted small">{{ __('inbound_resellers.reason_'.$allocation->suspended_reason) }}</div>@endif
                        @endif
                    </td>
                    <td class="text-nowrap">
                        <div class="icon-actions">
                            <x-icon-action icon="bx-edit" :label="__('app.edit')" :href="route('admin.inbound-allocations.edit', $allocation)" />
                            <x-icon-action icon="bx-calculator" variant="info" :label="__('inbound_resellers.bill_now')" :action="route('admin.inbound-allocations.bill', $allocation)" />
                            @if ($allocation->isActive())
                                <x-icon-action icon="bx-pause-circle" variant="warning" :label="__('inbound_resellers.suspend')" :action="route('admin.inbound-allocations.suspend', $allocation)" :confirm="__('inbound_resellers.suspend_confirm')" />
                            @else
                                <x-icon-action icon="bx-play-circle" variant="success" :label="__('inbound_resellers.resume')" :action="route('admin.inbound-allocations.resume', $allocation)" />
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">{{ __('inbound_resellers.no_allocations') }}</td></tr>
            @endforelse
        </x-table>
        @if ($allocations->hasPages())
            <div class="mt-3">{{ $allocations->links() }}</div>
        @endif
    </div>
</div>
@endsection
