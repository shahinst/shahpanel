@extends('layouts.panel')

@section('page_title', __('shahbot::admin.tab_payments'))

@section('panel_content')
@include('shahbot::_nav')

<div class="sb-filters">
    @foreach (['pending', 'awaiting_receipt', 'approved', 'rejected', 'cancelled', 'all'] as $value)
        <a href="{{ route('admin.shahbot.payments.index', ['status' => $value]) }}" @class(['is-active' => $status === $value])>
            {{ $value === 'all' ? __('shahbot::admin.all') : __('shahbot::admin.status_'.$value) }}
            @if ($value !== 'all')({{ persian_digits($counts[$value] ?? 0) }})@endif
        </a>
    @endforeach
</div>

<div class="sb-box">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>{{ __('shahbot::admin.col_user') }}</th>
                    <th>{{ __('shahbot::admin.col_amount') }}</th>
                    <th>{{ __('shahbot::admin.method') }}</th>
                    <th>{{ __('shahbot::admin.receipt') }}</th>
                    <th>{{ __('shahbot::admin.col_status') }}</th>
                    <th>{{ __('shahbot::admin.col_date') }}</th>
                    <th>{{ __('shahbot::admin.col_actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($payments as $payment)
                    <tr>
                        <td>{{ persian_digits($payment->id) }}</td>
                        <td><a href="{{ route('admin.shahbot.users.show', $payment->botUser) }}">{{ $payment->botUser->displayName() }}</a><div class="sb-muted">{{ $payment->botUser->telegram_id }}</div></td>
                        <td><b>{{ format_money($payment->amount) }}</b></td>
                        <td>{{ $payment->method === 'stars' ? __('shahbot::admin.method_stars').' ('.persian_digits($payment->stars).' ⭐)' : __('shahbot::admin.method_card') }}</td>
                        <td>
                            @if ($payment->receipt_file_id)
                                <a href="{{ route('admin.shahbot.payments.receipt', $payment) }}" target="_blank" rel="noopener"><i class="bx bx-image"></i> {{ __('shahbot::admin.view_receipt') }}</a>
                            @endif
                            @if ($payment->receipt_note)<div class="sb-muted">{{ $payment->receipt_note }}</div>@endif
                        </td>
                        <td>
                            <span @class(['sb-pill', 'ok' => $payment->status === 'approved', 'warn' => $payment->status === 'pending', 'bad' => $payment->status === 'rejected'])>{{ __('shahbot::admin.status_'.$payment->status) }}</span>
                            @if ($payment->reviewed_by)<div class="sb-muted">{{ $payment->reviewed_by }} · {{ jalali_date($payment->reviewed_at, 'm/d H:i') }}</div>@endif
                            @if ($payment->reject_reason)<div class="sb-muted">{{ $payment->reject_reason }}</div>@endif
                        </td>
                        <td>{{ jalali_date($payment->created_at, 'Y/m/d H:i') }}</td>
                        <td>
                            @if ($payment->status === 'pending')
                                <div class="d-flex flex-wrap gap-1 align-items-center">
                                    <form method="POST" action="{{ route('admin.shahbot.payments.approve', $payment) }}" data-confirm="{{ __('shahbot::admin.approve') }}?">
                                        @csrf
                                        <button class="btn btn-sm btn-success"><i class="bx bx-check"></i> {{ __('shahbot::admin.approve') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.shahbot.payments.reject', $payment) }}" class="d-flex gap-1">
                                        @csrf
                                        <input type="text" name="reason" class="form-control form-control-sm" placeholder="{{ __('shahbot::admin.reject_reason') }}" style="width:150px">
                                        <button class="btn btn-sm btn-outline-danger"><i class="bx bx-x"></i> {{ __('shahbot::admin.reject') }}</button>
                                    </form>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center sb-muted">{{ __('shahbot::admin.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
{{ $payments->links() }}

<div class="sb-box mt-3">
    <header>{{ __('shahbot::admin.online_payments') }}</header>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>#</th><th>{{ __('shahbot::admin.col_user') }}</th><th>{{ __('shahbot::admin.gateway') }}</th><th>{{ __('shahbot::admin.col_amount') }}</th><th>{{ __('shahbot::admin.net_amount') }}</th><th>{{ __('shahbot::admin.col_status') }}</th><th>{{ __('shahbot::admin.col_date') }}</th></tr></thead>
            <tbody>
                @forelse ($online as $link)
                    @php $gp = $link->gatewayPayment; @endphp
                    @continue($gp === null)
                    <tr>
                        <td>{{ persian_digits($gp->id) }}</td>
                        <td>@if ($link->botUser)<a href="{{ route('admin.shahbot.users.show', $link->botUser) }}">{{ $link->botUser->displayName() }}</a>@endif</td>
                        <td>{{ $gp->driver->label() }}@if ($gp->pay_currency) <span class="sb-muted">{{ strtoupper($gp->pay_currency) }}</span>@endif</td>
                        <td>{{ format_money($gp->gross_toman, 'IRT') }}</td>
                        <td>{{ format_money($gp->net_toman, 'IRT') }}</td>
                        <td><span @class(['sb-pill', 'ok' => $gp->status->value === 'completed', 'bad' => in_array($gp->status->value, ['failed', 'expired', 'cancelled'], true)])>{{ $gp->status->label() }}</span></td>
                        <td>{{ jalali_date($gp->created_at, 'Y/m/d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center sb-muted">{{ __('shahbot::admin.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
