@extends('layouts.panel')

@section('page_title', __('payment_gateways.history_title'))

@section('panel_content')
<x-page-header :title="__('payment_gateways.history_title')" :subtitle="__('payment_gateways.history_subtitle')">
    @if ($pendingCardCount > 0)
        <x-slot:actions>
            <span class="badge bg-warning text-dark">{{ persian_digits((string) $pendingCardCount) }} {{ __('payment_gateways.pending_card_reviews') }}</span>
        </x-slot:actions>
    @endif
</x-page-header>

<div class="panel-modern-card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">{{ __('payment_gateways.filter_driver') }}</label>
                <select name="driver" class="form-select">
                    <option value="">{{ __('payment_gateways.all') }}</option>
                    @foreach ($drivers as $driver)
                        <option value="{{ $driver->value }}" @selected(request('driver') === $driver->value)>{{ $driver->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">{{ __('payment_gateways.status') }}</label>
                <select name="status" class="form-select">
                    <option value="">{{ __('payment_gateways.all') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('app.search') }}</label>
                <input type="text" name="q" class="form-control" value="{{ request('q') }}" placeholder="{{ __('payment_gateways.search_placeholder') }}">
            </div>
            <div class="col-md-2">
                <x-button type="submit">{{ __('payment_gateways.filter') }}</x-button>
            </div>
        </form>
    </div>
</div>

<div class="panel-modern-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ __('payment_gateways.user') }}</th>
                        <th>{{ __('payment_gateways.filter_driver') }}</th>
                        <th>{{ __('payment_gateways.amount') }}</th>
                        <th>{{ __('payment_gateways.net_credit') }}</th>
                        <th>{{ __('payment_gateways.status') }}</th>
                        <th>{{ __('payment_gateways.date') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($payments as $payment)
                        <tr>
                            <td><code dir="ltr">{{ \Illuminate\Support\Str::limit($payment->uuid, 8, '') }}</code></td>
                            <td>
                                {{ $payment->user?->full_name ?? $payment->user?->username ?? '—' }}
                                <div class="small text-muted">{{ $payment->user?->role?->label() }}</div>
                            </td>
                            <td>{{ $payment->driver->label() }}</td>
                            <td>
                                @if ($payment->amount_usdt)
                                    <span dir="ltr">{{ persian_digits(number_format((float) $payment->amount_usdt, 2)) }} USDT</span><br>
                                @endif
                                {{ persian_digits(number_format((float) $payment->gross_toman, 0)) }} {{ __('packages.toman') }}
                            </td>
                            <td>{{ persian_digits(number_format((float) $payment->net_toman, 0)) }} {{ __('packages.toman') }}</td>
                            <td>{{ $payment->status->label() }}</td>
                            <td>{{ jalali_date($payment->created_at) }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.gateway-payments.show', $payment) }}" class="btn btn-sm btn-light">{{ __('app.view') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">{{ __('app.no_results') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if ($payments->hasPages())
        <div class="card-footer">{{ $payments->links() }}</div>
    @endif
</div>
@endsection
