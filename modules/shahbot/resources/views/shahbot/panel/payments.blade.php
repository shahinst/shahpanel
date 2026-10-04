@extends('layouts.panel')

@section('page_title', __('shahbot::admin.my_payments'))

@section('panel_content')
<div class="sbp-page">
    @include('shahbot::panel._nav', ['panel' => $panel])

    @if ($bots->isEmpty())
        <x-alert type="info">{{ __('shahbot::admin.my_bot_needed_first') }}</x-alert>
    @else
        <div class="d-flex flex-wrap gap-2 mb-3">
            @foreach (['pending', 'awaiting_receipt', 'approved', 'rejected', 'all'] as $s)
                <a href="{{ route($panel.'.shahbot.payments', ['status' => $s]) }}"
                   class="btn btn-sm {{ $status === $s ? 'btn-primary' : 'btn-outline-secondary' }} rounded-pill">
                    {{ __('shahbot::admin.pay_status_'.$s) }}
                    @if ($s !== 'all')<span class="opacity-75">({{ persian_digits((int) ($counts[$s] ?? 0)) }})</span>@endif
                </a>
            @endforeach
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="sbp-section"><i class="bx bx-receipt"></i> {{ __('shahbot::admin.my_payments') }}</h2>
                <p class="text-muted small">{{ __('shahbot::admin.my_payments_hint') }}</p>

                @forelse ($payments as $payment)
                    @php
                        $bot = $bots->get((int) $payment->botUser?->bot_id);
                        $mine = $bot && (int) $bot->owner_user_id === auth()->id();
                        $order = $payment->order ?? null;
                    @endphp
                    <div class="d-flex flex-wrap align-items-center gap-3 py-3 border-top">
                        @if ($payment->receipt_file_id)
                            <a href="{{ route($panel.'.shahbot.payments.receipt', $payment) }}" target="_blank" rel="noopener" class="flex-shrink-0">
                                <img src="{{ route($panel.'.shahbot.payments.receipt', $payment) }}" alt="" loading="lazy"
                                     style="width:76px;height:76px;object-fit:cover;border-radius:14px;border:1px solid rgba(0,0,0,.08)">
                            </a>
                        @else
                            <div class="flex-shrink-0 d-grid text-muted" style="width:76px;height:76px;place-items:center;border-radius:14px;background:rgba(99,102,241,.07)">
                                <i class="bx {{ $payment->method === 'nowpayments' ? 'bx-bitcoin' : 'bx-image' }} fs-3"></i>
                            </div>
                        @endif

                        <div class="flex-grow-1" style="min-width:12rem">
                            <div class="fw-bold">{{ format_money($payment->amount) }}
                                <span class="badge rounded-pill bg-light text-dark border ms-1">{{ __('shahbot::admin.pay_method_'.($payment->method ?: 'card')) }}</span>
                                <span class="badge rounded-pill {{ ['pending' => 'bg-warning text-dark', 'approved' => 'bg-success', 'rejected' => 'bg-danger'][$payment->status] ?? 'bg-secondary' }}">
                                    {{ __('shahbot::admin.pay_status_'.$payment->status) }}
                                </span>
                            </div>
                            <div class="small text-muted mt-1">
                                {{ trim(($payment->botUser?->first_name ?? '').' '.($payment->botUser?->last_name ?? '')) ?: '—' }}
                                @if ($payment->botUser?->username) · <span dir="ltr">{{ '@'.$payment->botUser->username }}</span>@endif
                                · {{ jalali_date($payment->created_at) }}
                                @if (! $mine && $bot) · {{ __('shahbot::admin.payment_of_seller', ['name' => $bot->owner?->full_name ?: $bot->owner?->username]) }}@endif
                            </div>
                            @if (is_array($order) && isset($order['duration']))
                                <div class="small mt-1"><i class="bx bx-cart"></i> {{ __('shahbot::admin.payment_for_order') }}
                                    @if (! empty($order['name']))« {{ $order['name'] }} »@endif</div>
                            @endif
                            @if ($payment->receipt_note)<div class="small mt-1">{{ $payment->receipt_note }}</div>@endif
                            @if ($payment->reject_reason)<div class="small text-danger mt-1">{{ $payment->reject_reason }}</div>@endif
                        </div>

                        @if ($mine && $payment->status === \Modules\ShahBot\Models\BotPayment::PENDING)
                            <div class="d-flex gap-2 flex-wrap">
                                <form method="POST" action="{{ route($panel.'.shahbot.payments.approve', $payment) }}" data-confirm="{{ __('shahbot::admin.payment_approve_confirm') }}">
                                    @csrf
                                    <button class="btn btn-success btn-sm rounded-pill"><i class="bx bx-check"></i> {{ __('shahbot::admin.approve') }}</button>
                                </form>
                                <form method="POST" action="{{ route($panel.'.shahbot.payments.reject', $payment) }}" class="d-flex gap-1">
                                    @csrf
                                    <input name="reason" maxlength="250" class="form-control form-control-sm" style="width:9rem" placeholder="{{ __('shahbot::admin.reject_reason') }}">
                                    <button class="btn btn-outline-danger btn-sm rounded-pill"><i class="bx bx-x"></i> {{ __('shahbot::admin.reject') }}</button>
                                </form>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-muted text-center py-4 mb-0">{{ __('shahbot::admin.my_payments_empty') }}</p>
                @endforelse

                @if ($payments->hasPages())<div class="mt-3">{{ $payments->links() }}</div>@endif
            </div>
        </div>
    @endif
</div>
@endsection
