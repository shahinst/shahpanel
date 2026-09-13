@extends('layouts.panel')

@section('page_title', __('payment_gateways.title'))

@section('panel_content')
<x-page-header :title="__('payment_gateways.title')" :subtitle="__('payment_gateways.subtitle')" />

<div class="row">
    <div class="col-lg-10">
        <div class="panel-modern-card mb-4">
            <div class="card-head"><h3>{{ __('payment_gateways.global_settings') }}</h3></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.payment-gateways.global.update') }}">
                    @csrf
                    @method('PUT')
                    <div class="row align-items-end">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="usdt_toman_rate">{{ __('payment_gateways.usdt_toman_rate') }}</label>
                            <input type="number" step="1" min="1" name="usdt_toman_rate" id="usdt_toman_rate"
                                   class="form-control" dir="ltr" required
                                   value="{{ old('usdt_toman_rate', $usdtTomanRate) }}">
                            <p class="text-muted small mt-1 mb-0">{{ __('payment_gateways.usdt_toman_rate_hint') }}</p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel-modern-card">
            <div class="card-head d-flex justify-content-between align-items-center">
                <h3>{{ __('payment_gateways.gateways_list') }}</h3>
                <a href="{{ route('admin.gateway-payments.index') }}" class="btn btn-sm btn-outline-primary">
                    {{ __('payment_gateways.menu_history') }}
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('payment_gateways.display_name') }}</th>
                                <th>{{ __('payment_gateways.mode') }}</th>
                                <th>{{ __('payment_gateways.commission') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($gateways as $gateway)
                                <tr>
                                    <td>
                                        <strong>{{ $gateway->display_name }}</strong>
                                        <div class="small text-muted">{{ $gateway->driver->label() }}</div>
                                        @if ($gateway->is_enabled)
                                            <span class="badge bg-success">{{ __('payment_gateways.enabled') }}</span>
                                        @else
                                            <span class="badge bg-secondary">{{ __('payment_gateways.disabled') }}</span>
                                        @endif
                                        @if (! $gateway->driver->isImplemented())
                                            <span class="badge bg-warning text-dark">{{ __('payment_gateways.coming_soon') }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $gateway->mode->label() }}</td>
                                    <td>
                                        @if (bccomp((string) $gateway->commission_percent, '0', 4) > 0)
                                            {{ persian_digits(rtrim(rtrim(number_format((float) $gateway->commission_percent, 2), '0'), '.')) }}%
                                        @endif
                                        @if (bccomp((string) $gateway->commission_fixed, '0', 2) > 0)
                                            @if (bccomp((string) $gateway->commission_percent, '0', 4) > 0) + @endif
                                            {{ persian_digits(number_format((float) $gateway->commission_fixed, 0)) }} تومان
                                        @endif
                                        @if (bccomp((string) $gateway->commission_percent, '0', 4) <= 0 && bccomp((string) $gateway->commission_fixed, '0', 2) <= 0)
                                            —
                                        @else
                                            <div class="small text-muted">{{ $gateway->commission_payer->label() }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.payment-gateways.edit', $gateway->driver->value) }}" class="btn btn-sm btn-outline-primary">
                                            {{ __('payment_gateways.configure') }}
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
