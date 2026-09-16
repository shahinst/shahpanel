@extends('layouts.panel')

@section('page_title', __('payment_gateways.return_success_title'))

@section('panel_content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="panel-modern-card text-center">
            <div class="card-body py-5">
                @if ($returnStatus === 'success')
                    <i class="bx bx-check-circle text-success display-4"></i>
                    <h2 class="h4 mt-3">{{ __('payment_gateways.return_success_title') }}</h2>
                    <p class="text-muted">{{ __('payment_gateways.return_success_message') }}</p>
                @else
                    <i class="bx bx-x-circle text-warning display-4"></i>
                    <h2 class="h4 mt-3">{{ __('payment_gateways.return_success_title') }}</h2>
                    <p class="text-muted">{{ __('payment_gateways.return_cancel_message') }}</p>
                @endif

                <p class="mb-1">{{ __('payment_gateways.status') }}: <strong>{{ $payment->status->label() }}</strong></p>
                <p class="text-muted small">{{ __('payment_gateways.net_credit') }}: {{ persian_digits(number_format((float) $payment->net_toman, 0)) }} {{ __('packages.toman') }}</p>

                <div class="mt-4">
                    <a href="{{ route($routePrefix.'.create') }}" class="btn btn-primary">{{ __('payment_gateways.back_to_top_up') }}</a>
                    @php
                        $paymentsRoute = match (auth()->user()?->role) {
                            \App\Enums\UserRole::Agent => 'agent.payment-requests.index',
                            \App\Enums\UserRole::Seller => 'seller.payment-requests.index',
                            \App\Enums\UserRole::Client => 'client.payment-requests.index',
                            default => null,
                        };
                    @endphp
                    @if ($paymentsRoute && Route::has($paymentsRoute))
                        <a href="{{ route($paymentsRoute) }}" class="btn btn-light">{{ __('payment_gateways.back_to_payments') }}</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
