@extends('layouts.panel')

@section('page_title', __('payment_gateways.payment_details'))

@section('panel_content')
<x-page-header :title="__('payment_gateways.payment_details')" :subtitle="$payment->uuid" />

<div class="row">
    <div class="col-lg-8">
        <div class="panel-modern-card mb-4">
            <div class="card-body">
                @include('admin.gateway-payments._details', ['payment' => $payment])
            </div>
        </div>

        @if ($payment->driver === \App\Enums\PaymentGatewayDriver::CardToCard && $payment->status === \App\Enums\GatewayPaymentStatus::Processing)
            <div class="panel-modern-card">
                <div class="card-head"><h3>{{ __('payment_gateways.review_actions') }}</h3></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <form method="POST" action="{{ route('admin.gateway-payments.approve', $payment) }}">
                                @csrf
                                <div class="mb-3">
                                    <label class="form-label">{{ __('payment_gateways.admin_note') }}</label>
                                    <textarea name="admin_note" class="form-control" rows="2"></textarea>
                                </div>
                                <x-button type="submit" class="btn-success"><i class="bx bx-check"></i> {{ __('payment_gateways.approve') }}</x-button>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <form method="POST" action="{{ route('admin.gateway-payments.reject', $payment) }}">
                                @csrf
                                <div class="mb-3">
                                    <label class="form-label">{{ __('payment_gateways.admin_note') }}</label>
                                    <textarea name="admin_note" class="form-control" rows="2"></textarea>
                                </div>
                                <x-button type="submit" class="btn-danger"><i class="bx bx-x"></i> {{ __('payment_gateways.reject') }}</x-button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <a href="{{ route('admin.gateway-payments.index') }}" class="btn btn-light mt-3">{{ __('app.back') }}</a>
    </div>
</div>
@endsection
