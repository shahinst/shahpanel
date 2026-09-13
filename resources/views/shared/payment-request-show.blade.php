<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <dl class="dl-horizontal">
                    <dt>{{ __('menu.amount') }}</dt>
                    <dd>{{ format_toman($paymentRequest->amount) }}</dd>
                    @if (isset($requesterBalance))
                        <dt>{{ __('wallet.requester_balance') }}</dt>
                        <dd><strong>{{ format_toman($requesterBalance) }}</strong></dd>
                    @endif
                    <dt>{{ __('menu.tracking_number') }}</dt>
                    <dd>{{ $paymentRequest->tracking_number }}</dd>
                    <dt>وضعیت</dt>
                    <dd>{{ $paymentRequest->status->value }}</dd>
                    <dt>تاریخ</dt>
                    <dd>{{ jalali_date($paymentRequest->created_at) }}</dd>
                </dl>

                @if ($paymentRequest->status === \App\Enums\PaymentRequestStatus::Pending && isset($approveRoute))
                    <hr>
                    <form method="POST" action="{{ $approveRoute }}" class="form-inline" style="margin-bottom:10px;">
                        @csrf
                        <x-button type="submit" size="sm">{{ __('menu.approve') }}</x-button>
                    </form>
                    <form method="POST" action="{{ $rejectRoute }}" class="form-inline">
                        @csrf
                        <div class="form-group">
                            <input name="admin_note" required class="form-control" placeholder="{{ __('menu.admin_note') }}">
                        </div>
                        <x-button type="submit" variant="danger" size="sm">{{ __('menu.reject') }}</x-button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
