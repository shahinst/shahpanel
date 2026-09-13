@extends('layouts.panel')

@section('page_title', __('menu.payment_requests'))

@section('panel_content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <dl class="dl-horizontal">
                    <dt>درخواست‌دهنده</dt>
                    <dd>{{ $paymentRequest->requester->full_name }}</dd>
                    <dt>{{ __('menu.amount') }}</dt>
                    <dd>{{ format_toman($paymentRequest->amount) }}</dd>
                    <dt>{{ __('menu.tracking_number') }}</dt>
                    <dd>{{ $paymentRequest->tracking_number }}</dd>
                    <dt>وضعیت</dt>
                    <dd>{{ $paymentRequest->status->value }}</dd>
                </dl>

                @if ($paymentRequest->status === \App\Enums\PaymentRequestStatus::Pending)
                    <hr>
                    <form method="POST" action="{{ route('admin.payment-requests.approve', $paymentRequest) }}" class="form-inline" style="margin-bottom:10px;">
                        @csrf
                        <div class="form-group">
                            <input name="admin_note" placeholder="{{ __('menu.admin_note') }}" class="form-control">
                        </div>
                        <x-button type="submit" size="sm">{{ __('menu.approve') }}</x-button>
                    </form>
                    <form method="POST" action="{{ route('admin.payment-requests.reject', $paymentRequest) }}" class="form-inline">
                        @csrf
                        <div class="form-group">
                            <input name="admin_note" required placeholder="{{ __('menu.admin_note') }}" class="form-control">
                        </div>
                        <x-button type="submit" variant="danger" size="sm">{{ __('menu.reject') }}</x-button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
