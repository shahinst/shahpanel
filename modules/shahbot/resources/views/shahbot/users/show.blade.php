@extends('layouts.panel')

@section('page_title', __('shahbot::admin.user_title', ['name' => $botUser->displayName()]))

@section('panel_content')
@include('shahbot::_nav')

<div class="sb-grid-2">
    <div class="sb-box">
        <header>
            <span><i class="bx bx-user"></i> {{ $botUser->displayName() }}</span>
            <form method="POST" action="{{ route('admin.shahbot.users.block', $botUser) }}">
                @csrf
                <button class="btn btn-sm {{ $botUser->is_blocked ? 'btn-success' : 'btn-outline-danger' }}">{{ $botUser->is_blocked ? __('shahbot::admin.unblock') : __('shahbot::admin.block') }}</button>
            </form>
        </header>
        <div class="sb-body">
            <table class="table table-sm mb-0">
                <tr><th>{{ __('shahbot::admin.col_telegram_id') }}</th><td><code>{{ $botUser->telegram_id }}</code> @if ($botUser->username)<a href="https://t.me/{{ $botUser->username }}" target="_blank" rel="noopener">{{ '@'.$botUser->username }}</a>@endif</td></tr>
                <tr><th>{{ __('shahbot::admin.col_phone') }}</th><td>{{ $botUser->phone ? persian_digits($botUser->phone) : '—' }}</td></tr>
                <tr><th>{{ __('shahbot::admin.wallet_balance') }}</th><td><b>{{ $balance !== null ? format_money($balance) : '—' }}</b></td></tr>
                <tr><th>{{ __('shahbot::admin.panel_client') }}</th><td>@if ($botUser->client)<a href="{{ route('admin.clients.show', $botUser->client) }}">{{ $botUser->client->username }}</a>@else — @endif</td></tr>
                <tr><th>{{ __('shahbot::admin.referrer') }}</th><td>@if ($botUser->referrer)<a href="{{ route('admin.shahbot.users.show', $botUser->referrer) }}">{{ $botUser->referrer->displayName() }}</a>@else — @endif</td></tr>
                <tr><th>{{ __('shahbot::admin.referrals') }}</th><td>{{ persian_digits($referrals) }}</td></tr>
                <tr><th>{{ __('shahbot::admin.col_joined') }}</th><td>{{ jalali_date($botUser->created_at, 'Y/m/d H:i') }}</td></tr>
                <tr><th>{{ __('shahbot::admin.col_status') }}</th><td>
                    @if ($botUser->is_blocked)<span class="sb-pill bad">{{ __('shahbot::admin.blocked') }}</span>@endif
                    @if ($botUser->bot_blocked_by_user)<span class="sb-pill warn">{{ __('shahbot::admin.left_bot') }}</span>@endif
                    @if (! $botUser->is_blocked && ! $botUser->bot_blocked_by_user)<span class="sb-pill ok">{{ __('shahbot::admin.active') }}</span>@endif
                </td></tr>
            </table>
        </div>
    </div>

    <div>
        <div class="sb-box">
            <header>{{ __('shahbot::admin.send_message') }}</header>
            <div class="sb-body">
                <form method="POST" action="{{ route('admin.shahbot.users.message', $botUser) }}">
                    @csrf
                    <textarea name="text" rows="3" class="form-control mb-2" required maxlength="3500"></textarea>
                    <button class="btn btn-primary btn-sm"><i class="bx bx-send"></i> {{ __('shahbot::admin.send') }}</button>
                </form>
            </div>
        </div>
        <div class="sb-box">
            <header>{{ __('shahbot::admin.wallet_adjust') }}</header>
            <div class="sb-body">
                <form method="POST" action="{{ route('admin.shahbot.users.wallet', $botUser) }}" class="row g-2" data-confirm="{{ __('shahbot::admin.wallet_adjust') }}?">
                    @csrf
                    <div class="col-sm-4">
                        <select name="direction" class="form-select">
                            <option value="credit">{{ __('shahbot::admin.credit') }}</option>
                            <option value="debit">{{ __('shahbot::admin.debit') }}</option>
                        </select>
                    </div>
                    <div class="col-sm-4"><input type="number" name="amount" min="1" step="any" class="form-control" placeholder="{{ __('shahbot::admin.amount') }}" required></div>
                    <div class="col-sm-4"><input type="text" name="note" class="form-control" placeholder="{{ __('shahbot::admin.note') }}"></div>
                    <div class="col-12"><button class="btn btn-sm btn-warning">{{ __('shahbot::admin.save') }}</button> <span class="sb-muted">{{ __('shahbot::admin.wallet_adjust_hint') }}</span></div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="sb-box">
    <header>{{ __('shahbot::admin.services') }} ({{ persian_digits($accounts->count()) }})</header>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>{{ __('shahbot::admin.col_account') }}</th><th>{{ __('shahbot::admin.col_service') }}</th><th>{{ __('shahbot::admin.col_status') }}</th><th>{{ __('accounts.expiry') }}</th></tr></thead>
            <tbody>
                @forelse ($accounts as $account)
                    <tr>
                        <td><a href="{{ route('admin.accounts.show', $account) }}">{{ $account->remote_username }}</a></td>
                        <td>{{ $account->package?->name ?? '—' }}</td>
                        <td><span class="sb-pill">{{ __('shahbot::bot.status_'.$account->status->value, [], 'fa') }}</span></td>
                        <td>{{ $account->expiry_at ? jalali_date($account->expiry_at, 'Y/m/d') : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center sb-muted">{{ __('shahbot::admin.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="sb-box">
    <header>{{ __('shahbot::admin.orders') }}</header>
    @include('shahbot::_orders_table', ['orders' => $orders->each(fn ($o) => $o->setRelation('botUser', $botUser))])
</div>

<div class="sb-box">
    <header>{{ __('shahbot::admin.payments') }}</header>
    <div class="table-responsive">
        <table class="table align-middle">
            <tbody>
                @forelse ($payments as $payment)
                    <tr>
                        <td>#{{ persian_digits($payment->id) }}</td>
                        <td>{{ format_money($payment->amount) }}</td>
                        <td><span class="sb-pill">{{ __('shahbot::admin.status_'.$payment->status) }}</span></td>
                        <td>{{ jalali_date($payment->created_at, 'Y/m/d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td class="text-center sb-muted">{{ __('shahbot::admin.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
