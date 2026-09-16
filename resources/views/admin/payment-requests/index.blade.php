@extends('layouts.panel')

@section('page_title', __('menu.payment_requests'))

@section('panel_content')
@if (! empty($migrationPending))
    <x-alert type="warning" style="margin-bottom:15px;">{{ __('wallet.migration_required') }}</x-alert>
@endif

@include('shared.payment-requests.summary-box', ['chargeSummaryCards' => $chargeSummaryCards ?? []])

@php
    $highlightAdjustmentId = session('highlight_adjustment_id');
@endphp

<div class="row">
    <div class="col-12">
        <div class="card" id="adjustment-history">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h4 class="card-title mb-0">{{ __('wallet.adjustment_history') }}</h4>
                    <p class="text-muted small mb-0 mt-1">{{ __('wallet.manual_charge_record_hint') }}</p>
                </div>
                <x-button :href="route('admin.payment-requests.charge')" size="sm">{{ __('wallet.manual_charge') }}</x-button>
            </div>
            <div class="card-body">
                <x-table :headers="[__('ui.col_user'), __('wallet.direction'), __('menu.amount'), __('ui.col_admin'), __('ui.col_date'), __('ui.col_note')]">
                    @forelse ($adjustments as $adjustment)
                        <tr class="{{ (int) $highlightAdjustmentId === (int) $adjustment->id ? 'table-success' : '' }}">
                            <td>{{ $adjustment->user->full_name }}</td>
                            <td>{{ $adjustment->isCredit() ? __('wallet.credit') : __('wallet.debit') }}</td>
                            {{-- جدول wallet_adjustments ستون currency ندارد؛ ارز از تراکنش متصل خوانده می‌شود و در نبود آن ارز پیش‌فرض پنل. --}}
                            <td>{{ format_money($adjustment->amount, $adjustment->transaction?->currency ?? \App\Enums\MoneyCurrency::default()) }}</td>
                            <td>{{ $adjustment->admin->full_name }}</td>
                            <td>{{ jalali_date($adjustment->created_at) }}</td>
                            <td><small class="text-muted">{{ $adjustment->note ?: '—' }}</small></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">{{ __('app.no_results') }}</td></tr>
                    @endforelse
                </x-table>
            </div>
            @if ($adjustments->hasPages())
                <div class="card-footer">{{ $adjustments->links() }}</div>
            @endif
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title mb-0">{{ __('wallet.all_charge_requests') }}</h4>
            </div>
            <div class="card-body">
                <x-table :headers="[__('ui.col_requester'), __('ui.col_role'), __('ui.col_path'), __('ui.col_approver'), __('menu.amount'), __('app.status'), __('ui.col_date'), __('app.actions')]">
                    @forelse ($paymentRequests as $paymentRequest)
                        @php
                            $requester = $paymentRequest->requester;
                            $approver = $paymentRequest->approver;
                            $flow = match (true) {
                                $requester?->role === \App\Enums\UserRole::Seller && $approver?->role === \App\Enums\UserRole::Agent
                                    => __('wallet.flow_seller_to_agent'),
                                $requester?->role === \App\Enums\UserRole::Agent && $approver?->role === \App\Enums\UserRole::Admin
                                    => __('wallet.flow_agent_to_admin'),
                                default => '—',
                            };
                        @endphp
                        <tr>
                            <td>{{ $requester?->full_name ?? '—' }}</td>
                            <td>{{ $requester?->role?->label() ?? '—' }}</td>
                            <td>{{ $flow }}</td>
                            <td>{{ $approver?->full_name ?? '—' }}</td>
                            <td>{{ format_money($paymentRequest->amount, $paymentRequest->moneyCurrency()) }}</td>
                            <td>{{ $paymentRequest->status->label() }}</td>
                            <td>{{ jalali_date($paymentRequest->created_at) }}</td>
                            <td><a href="{{ route('admin.payment-requests.show', $paymentRequest) }}" class="btn btn-sm btn-light">{{ __('app.view') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">{{ __('app.no_results') }}</td></tr>
                    @endforelse
                </x-table>
            </div>
            @if ($paymentRequests->hasPages())
                <div class="card-footer">{{ $paymentRequests->links() }}</div>
            @endif
        </div>
    </div>
</div>

@if (session('highlight_adjustment_id'))
    @push('scripts')
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var target = document.getElementById('adjustment-history');
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    </script>
    @endpush
@endif
@endsection
