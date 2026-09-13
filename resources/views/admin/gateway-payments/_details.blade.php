<dl class="row mb-0">
    <dt class="col-sm-4">{{ __('payment_gateways.user') }}</dt>
    <dd class="col-sm-8">{{ $payment->user?->full_name ?? '—' }} ({{ $payment->user?->username }})</dd>

    <dt class="col-sm-4">{{ __('payment_gateways.filter_driver') }}</dt>
    <dd class="col-sm-8">{{ $payment->driver->label() }}</dd>

    <dt class="col-sm-4">{{ __('payment_gateways.status') }}</dt>
    <dd class="col-sm-8">{{ $payment->status->label() }}</dd>

    @if ($payment->amount_usdt)
        <dt class="col-sm-4">{{ __('payment_gateways.amount_usdt') }}</dt>
        <dd class="col-sm-8" dir="ltr">{{ persian_digits(number_format((float) $payment->amount_usdt, 2)) }} USDT</dd>
    @endif

    @if ($payment->amount_toman)
        <dt class="col-sm-4">{{ __('payment_gateways.amount_toman') }}</dt>
        <dd class="col-sm-8">{{ persian_digits(number_format((float) $payment->amount_toman, 0)) }} تومان</dd>
    @endif

    <dt class="col-sm-4">{{ __('payment_gateways.toman_equivalent') }}</dt>
    <dd class="col-sm-8">{{ persian_digits(number_format((float) $payment->gross_toman, 0)) }} تومان</dd>

    <dt class="col-sm-4">{{ __('payment_gateways.commission_amount') }}</dt>
    <dd class="col-sm-8">{{ persian_digits(number_format((float) $payment->commission_toman, 0)) }} تومان ({{ $payment->commission_payer->label() }})</dd>

    <dt class="col-sm-4">{{ __('payment_gateways.net_credit') }}</dt>
    <dd class="col-sm-8 fw-bold">{{ persian_digits(number_format((float) $payment->net_toman, 0)) }} تومان</dd>

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

    @if ($payment->external_payment_id)
        <dt class="col-sm-4">{{ __('payment_gateways.external_id') }}</dt>
        <dd class="col-sm-8"><code dir="ltr">{{ $payment->external_payment_id }}</code></dd>
    @endif

    @if ($payment->paid_at)
        <dt class="col-sm-4">{{ __('payment_gateways.paid_at') }}</dt>
        <dd class="col-sm-8">{{ jalali_date($payment->paid_at) }}</dd>
    @endif

    @if ($payment->reviewer)
        <dt class="col-sm-4">{{ __('payment_gateways.reviewer') }}</dt>
        <dd class="col-sm-8">{{ $payment->reviewer->full_name ?? $payment->reviewer->username }} — {{ jalali_date($payment->reviewed_at) }}</dd>
    @endif

    @if ($payment->admin_note)
        <dt class="col-sm-4">{{ __('payment_gateways.admin_note') }}</dt>
        <dd class="col-sm-8">{{ $payment->admin_note }}</dd>
    @endif

    <dt class="col-sm-4">{{ __('app.date') }}</dt>
    <dd class="col-sm-8">{{ jalali_date($payment->created_at) }}</dd>
</dl>
