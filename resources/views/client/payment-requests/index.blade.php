@extends('layouts.panel')

@section('page_title', __('menu.payment_requests'))

@section('panel_content')
<x-card>
    <x-button :href="route('client.payment-requests.create')" class="mb-3">{{ __('menu.new_charge_request') }}</x-button>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>{{ __('menu.amount') }}</th>
                    <th>{{ __('menu.tracking_number') }}</th>
                    <th>{{ __('app.status') }}</th>
                    <th>{{ __('accounting.created_at') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($paymentRequests as $item)
                    <tr>
                        <td>{{ format_toman($item->amount) }}</td>
                        <td>{{ $item->tracking_number }}</td>
                        <td>{{ $item->status->value }}</td>
                        <td>{{ jalali_date($item->created_at) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted">—</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $paymentRequests->links() }}
</x-card>
@endsection
