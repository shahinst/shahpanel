@extends('layouts.panel')

@section('page_title', __('menu.payment_requests'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('menu.payment_requests'),
    'subtitle' => __('ui.payment_requests_agent_subtitle'),
    'icon' => 'bx-wallet',
    'actions' => '<a href="'.route('agent.payment-requests.create').'" class="btn btn-light btn-sm"><i class="bx bx-plus"></i> '.e(__('menu.new_charge_request')).'</a>',
])

@include('shared.payment-requests.summary-box', ['chargeSummaryCards' => $chargeSummaryCards ?? []])

<div class="panel-modern-card">
    <div class="card-head"><h3>{{ __('menu.payment_requests') }}</h3></div>
    <div class="card-body">
        <x-table :headers="[__('ui.col_type'), __('ui.col_requester'), __('ui.col_approver'), __('menu.amount'), __('app.status'), __('ui.col_date'), __('app.actions')]">
            @forelse ($paymentRequests as $item)
                @php
                    $isIncoming = (int) $item->approver_user_id === (int) auth()->id();
                    $typeLabel = $isIncoming ? __('wallet.incoming_request') : __('wallet.outgoing_request');
                @endphp
                <tr>
                    <td>{{ $typeLabel }}</td>
                    <td>{{ $item->requester->full_name }}</td>
                    <td>{{ $item->approver->full_name }}</td>
                    <td>{{ format_money($item->amount, $item->moneyCurrency()) }}</td>
                    <td>{{ $item->status->label() }}</td>
                    <td>{{ jalali_date($item->created_at) }}</td>
                    <td><a href="{{ route('agent.payment-requests.show', $item) }}" class="btn btn-sm btn-primary">{{ __('app.view') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">{{ __('app.no_results') }}</td></tr>
            @endforelse
        </x-table>
    </div>
    @if ($paymentRequests->hasPages())
        <div class="card-foot">{{ $paymentRequests->links() }}</div>
    @endif
</div>
@endsection
