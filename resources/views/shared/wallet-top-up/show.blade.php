@extends('layouts.panel')

@section('page_title', __('payment_gateways.payment_details'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('payment_gateways.payment_details'),
    'subtitle' => $payment->paymentGateway?->display_name ?? $payment->driver->label(),
    'icon' => 'bx-receipt',
])

<div class="row">
    <div class="col-lg-8">
        <div class="panel-modern-card mb-4">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('payment_gateways.status') }}</dt>
                    <dd class="col-sm-8">{{ $payment->status->label() }}</dd>

                    @if ($payment->driver === \App\Enums\PaymentGatewayDriver::NowPayments && $payment->amount_usdt)
                        <dt class="col-sm-4">{{ __('payment_gateways.amount_usdt') }}</dt>
                        <dd class="col-sm-8" dir="ltr">{{ persian_digits(number_format((float) $payment->amount_usdt, 2)) }} USDT</dd>
                    @endif

                    @if ($payment->amount_toman)
                        <dt class="col-sm-4">{{ __('payment_gateways.amount_toman') }}</dt>
                        <dd class="col-sm-8">{{ persian_digits(number_format((float) $payment->amount_toman, 0)) }} {{ __('packages.toman') }}</dd>
                    @endif

                    <dt class="col-sm-4">{{ __('payment_gateways.toman_equivalent') }}</dt>
                    <dd class="col-sm-8">{{ persian_digits(number_format((float) $payment->gross_toman, 0)) }} {{ __('packages.toman') }}</dd>

                    <dt class="col-sm-4">{{ __('payment_gateways.net_credit') }}</dt>
                    <dd class="col-sm-8 fw-bold text-success">{{ persian_digits(number_format((float) $payment->net_toman, 0)) }} {{ __('packages.toman') }}</dd>

                    @if ($payment->tracking_number)
                        <dt class="col-sm-4">{{ __('payment_gateways.tracking_number') }}</dt>
                        <dd class="col-sm-8" dir="ltr">{{ persian_digits($payment->tracking_number) }}</dd>
                    @endif

                    @if ($payment->card_last4)
                        <dt class="col-sm-4">{{ __('payment_gateways.card_last4') }}</dt>
                        <dd class="col-sm-8" dir="ltr">{{ persian_digits($payment->card_last4) }}</dd>
                    @endif

                    @if ($payment->requester_note)
                        <dt class="col-sm-4">{{ __('payment_gateways.requester_note') }}</dt>
                        <dd class="col-sm-8">{{ $payment->requester_note }}</dd>
                    @endif

                    @if ($payment->pay_amount && $payment->driver !== \App\Enums\PaymentGatewayDriver::CardToCard)
                        <dt class="col-sm-4">{{ __('payment_gateways.pay_amount') }}</dt>
                        <dd class="col-sm-8" dir="ltr">{{ persian_digits($payment->pay_amount) }} {{ $payment->pay_currency }}</dd>
                    @endif

                    @if ($payment->pay_address)
                        <dt class="col-sm-4">{{ __('payment_gateways.pay_address') }}</dt>
                        <dd class="col-sm-8"><code dir="ltr" class="user-select-all">{{ $payment->pay_address }}</code></dd>
                    @endif
                </dl>
            </div>
        </div>

        @if ($payment->driver === \App\Enums\PaymentGatewayDriver::CardToCard && $destinationCard)
            <div class="alert alert-info">
                <strong>{{ __('payment_gateways.destination_card') }}</strong>
                <div dir="ltr" class="mt-2 user-select-all">{{ $destinationCard->configValue('card_number') }}</div>
                <div>{{ $destinationCard->configValue('card_holder') }}</div>
            </div>
        @endif

        @if ($payment->invoice_url)
            <a href="{{ $payment->invoice_url }}" class="btn btn-primary" target="_blank" rel="noopener">
                <i class="bx bx-link-external"></i> {{ __('payment_gateways.open_invoice') }}
            </a>
        @elseif ($payment->driver === \App\Enums\PaymentGatewayDriver::CardToCard)
            <p class="text-muted">{{ __('payment_gateways.card_to_card_pending_message') }}</p>
        @elseif ($payment->driver === \App\Enums\PaymentGatewayDriver::NowPayments)
            <p class="text-muted">{{ __('payment_gateways.invoice_url_missing') }}</p>
        @endif

        <a href="{{ route($routePrefix.'.create') }}" class="btn btn-light ms-2">{{ __('payment_gateways.back_to_top_up') }}</a>
    </div>
</div>
@endsection
