@extends('layouts.panel')

@section('page_title', __('menu.payment_requests'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('menu.payment_requests'),
    'subtitle' => 'درخواست‌های شارژ کیف پول',
    'icon' => 'bx-wallet',
    'actions' => '<a href="'.route('seller.payment-requests.create').'" class="btn btn-light btn-sm"><i class="bx bx-plus"></i> '.e(__('menu.new_charge_request')).'</a>',
])

@include('shared.payment-requests.summary-box', ['chargeSummaryCards' => $chargeSummaryCards ?? []])

<div class="panel-modern-card">
    <div class="card-head"><h3>{{ __('menu.payment_requests') }}</h3></div>
    <div class="card-body">
        <x-table :headers="[__('menu.amount'), 'وضعیت', 'تاریخ', __('app.actions')]">
            @forelse ($paymentRequests as $item)
                <tr>
                    <td>{{ format_toman($item->amount) }}</td>
                    <td>{{ $item->status->label() }}</td>
                    <td>{{ jalali_date($item->created_at) }}</td>
                    <td><a href="{{ route('seller.payment-requests.show', $item) }}" class="btn btn-sm btn-primary">{{ __('app.view') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-4">{{ __('app.no_results') }}</td></tr>
            @endforelse
        </x-table>
    </div>
    @if ($paymentRequests->hasPages())
        <div class="card-foot">{{ $paymentRequests->links() }}</div>
    @endif
</div>
@endsection
