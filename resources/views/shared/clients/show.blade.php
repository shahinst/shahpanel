@extends('layouts.panel')

@section('page_title', $client->full_name)

@section('panel_content')
@php
    use App\Enums\AccountStatus;
    use App\Enums\PaymentRequestStatus;

    $statusBadge = fn (AccountStatus $status): array => match ($status) {
        AccountStatus::Active => ['success', __('accounts.status_active')],
        AccountStatus::Disabled => ['secondary', __('accounts.status_disabled')],
        AccountStatus::Expired => ['danger', __('accounts.status_expired')],
        AccountStatus::Exhausted => ['warning', __('accounts.status_exhausted')],
        AccountStatus::Pending => ['info', __('accounts.status_pending')],
    };

    $paymentStatusLabel = fn (PaymentRequestStatus $status): array => match ($status) {
        PaymentRequestStatus::Pending => ['warning', __('clients.payment_pending')],
        PaymentRequestStatus::Approved => ['success', __('clients.payment_approved')],
        PaymentRequestStatus::Rejected => ['danger', __('clients.payment_rejected')],
    };

    $txLabel = fn (string $type): string => match ($type) {
        'charge' => __('clients.tx_charge'),
        'purchase' => __('clients.tx_purchase'),
        'renewal' => __('clients.tx_renewal'),
        'refund' => __('clients.tx_refund'),
        'reactivation' => __('clients.tx_reactivation'),
        'adjustment' => __('clients.tx_adjustment'),
        default => $type,
    };

    $portalBtn = $canImpersonate ?? auth()->user()->can('impersonate', $client)
        ? '<form method="POST" action="'.route($panel.'.clients.impersonate', $client).'" class="d-inline">'.csrf_field()
            .'<button type="submit" class="btn btn-light btn-sm"><i class="bx bx-log-in-circle"></i> '.e(__('clients.enter_portal')).'</button></form>'
        : '';
@endphp

@push('styles')
<style>
.staff-client-kpi {
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    background: #fff;
    padding: 1.1rem 1.2rem;
    height: 100%;
    display: flex;
    align-items: flex-start;
    gap: .9rem;
    box-shadow: 0 4px 16px rgba(15, 23, 42, .04);
}
.staff-client-kpi .kpi-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    flex-shrink: 0;
}
.staff-client-kpi .kpi-value { font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0; }
.staff-client-kpi .kpi-label { font-size: .78rem; color: #64748b; margin: 0 0 .25rem; }
.staff-client-account-row {
    border: 1px solid #e9ecef;
    border-radius: 14px;
    padding: 1rem 1.1rem;
    margin-bottom: .75rem;
    background: #fff;
    transition: box-shadow .15s ease;
}
.staff-client-account-row:hover { box-shadow: 0 6px 20px rgba(15, 23, 42, .06); }
.staff-client-account-row:last-child { margin-bottom: 0; }
.staff-client-card-number {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 1.02rem;
    font-weight: 700;
    letter-spacing: .06em;
    direction: ltr;
    text-align: center;
    padding: .6rem .75rem;
    background: #f8fafc;
    border-radius: 10px;
    border: 1px solid #dbeafe;
}
.staff-client-portal-box {
    background: linear-gradient(180deg, #eff6ff 0%, #fff 100%);
    border: 1px dashed #93c5fd;
    border-radius: 14px;
    padding: 1rem 1.15rem;
}
.staff-client-portal-box code { font-size: .9rem; }
</style>
@endpush

@include('partials.panel-page-hero', [
    'title' => $client->full_name,
    'subtitle' => __('clients.client_profile').' — '.$client->username,
    'icon' => 'bx-user-circle',
    'actions' => $portalBtn.'
        <a href="'.route($panel.'.accounts.create', ['client_mode' => 'existing', 'client_user_id' => $client->id]).'" class="btn btn-outline-light btn-sm"><i class="bx bx-plus-circle"></i> '.e(__('clients.new_account_for_client')).'</a>
        <a href="'.route($panel.'.clients.index').'" class="btn btn-outline-light btn-sm"><i class="bx bx-arrow-back"></i> '.e(__('app.back')).'</a>
    ',
])

<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="staff-client-kpi">
            <div class="kpi-icon bg-soft-success text-success"><i class="bx bx-wallet"></i></div>
            <div>
                <p class="kpi-label">{{ __('clients.wallet_balance') }}</p>
                <p class="kpi-value">{{ format_money($wallet->balance, $wallet->moneyCurrency()) }}</p>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="staff-client-kpi">
            <div class="kpi-icon bg-soft-primary text-primary"><i class="bx bx-server"></i></div>
            <div>
                <p class="kpi-label">{{ __('clients.my_accounts') }}</p>
                <p class="kpi-value">{{ persian_digits($accounts->count()) }}</p>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="staff-client-kpi">
            <div class="kpi-icon bg-soft-warning text-warning"><i class="bx bx-time-five"></i></div>
            <div>
                <p class="kpi-label">{{ __('clients.pending_charge_requests') }}</p>
                <p class="kpi-value">{{ persian_digits($pendingChargeCount ?? 0) }}</p>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="staff-client-kpi">
            <div class="kpi-icon bg-soft-info text-info"><i class="bx bx-link"></i></div>
            <div>
                <p class="kpi-label">{{ __('clients.portal_login_url') }}</p>
                <p class="kpi-value" style="font-size:.85rem;"><code dir="ltr" class="text-break">{{ $clientLoginUrl ?? client_portal_login_url() }}</code></p>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="panel-modern-card">
            <div class="card-head">
                <h3><i class="bx bx-server text-primary"></i> {{ __('clients.my_accounts') }}</h3>
            </div>
            <div class="card-body">
                @if ($accounts->isEmpty())
                    <p class="text-muted mb-0">{{ __('clients.no_accounts') }}</p>
                @else
                    @foreach ($accounts as $account)
                        @php [$badgeColor, $badgeLabel] = $statusBadge($account->status); @endphp
                        <div class="staff-client-account-row">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                <div>
                                    <div class="fw-bold">{{ $account->remote_username }}</div>
                                    <div class="text-muted small">{{ $account->package?->name ?? '—' }}
                                        @if ($account->packageDuration)
                                            — {{ $account->packageDuration->displayLabel() }}
                                        @endif
                                    </div>
                                </div>
                                <span class="badge bg-{{ $badgeColor }}">{{ $badgeLabel }}</span>
                            </div>
                            <div class="row g-2 small text-muted mb-2">
                                <div class="col-sm-6">
                                    <i class="bx bx-calendar"></i> {{ __('accounts.expiry') }}:
                                    <strong class="text-dark">{{ $account->expiry_at ? jalali_date($account->expiry_at) : __('accounts.no_expiry') }}</strong>
                                </div>
                                @if ($account->server)
                                    <div class="col-sm-6">
                                        <i class="bx bx-hdd"></i> {{ __('clients.server') }}: <strong class="text-dark">{{ $account->server->name }}</strong>
                                    </div>
                                @endif
                                @if ($account->data_limit_bytes && ! $account->isUnlimited())
                                    @php
                                        $usedPct = min(100, round(($account->data_used_bytes / max(1, $account->data_limit_bytes)) * 100, 1));
                                    @endphp
                                    <div class="col-12">
                                        <i class="bx bx-pie-chart-alt-2"></i> {{ __('clients.data_usage') }}:
                                        {{ format_data_size($account->data_used_bytes) }} / {{ format_data_size($account->data_limit_bytes) }}
                                        ({{ persian_digits($usedPct) }}٪)
                                    </div>
                                @elseif ($account->isUnlimited())
                                    <div class="col-12"><i class="bx bx-infinite"></i> {{ __('clients.unlimited_data') }}</div>
                                @endif
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="{{ route($panel.'.clients.accounts.show', [$client, $account]) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bx bx-show"></i> {{ __('clients.view_account') }}
                                </a>
                                @can('update', $account)
                                    <a href="{{ route($panel.'.accounts.renew-form', $account) }}" class="btn btn-sm btn-primary">
                                        <i class="bx bx-revision"></i> {{ __('clients.renew_from_panel') }}
                                    </a>
                                @endcan
                                @if ($canReassignAccounts && $transferTargets->isNotEmpty())
                                    <form method="POST" action="{{ route($panel.'.clients.accounts.reassign', $account) }}" class="d-flex gap-1 flex-grow-1" style="min-width:200px;">
                                        @csrf
                                        <select name="target_client_id" class="form-control form-control-sm" required>
                                            <option value="">{{ __('clients.transfer_to_client') }}…</option>
                                            @foreach ($transferTargets as $target)
                                                <option value="{{ $target->id }}">{{ $target->full_name }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bx bx-transfer"></i></button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>

        @if ($canAssignAccounts && $unassignedAccounts->isNotEmpty())
            <div class="panel-modern-card mt-3">
                <div class="card-head"><h3><i class="bx bx-link-alt"></i> {{ __('clients.unassigned_accounts') }}</h3></div>
                <div class="card-body">
                    <p class="text-muted small">{{ __('clients.unassigned_accounts_hint') }}</p>
                    @foreach ($unassignedAccounts as $unassigned)
                        <div class="staff-client-account-row">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <strong>{{ $unassigned->remote_username }}</strong>
                                    <span class="text-muted small">— {{ $unassigned->package?->name }}</span>
                                </div>
                                <form method="POST" action="{{ route($panel.'.clients.accounts.assign', [$client, $unassigned]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-primary"><i class="bx bx-user-plus"></i> {{ __('clients.assign_account') }}</button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <div class="col-xl-4">
        <div class="panel-modern-card mb-3">
            <div class="card-head d-flex justify-content-between align-items-center">
                <h3><i class="bx bx-log-in-circle"></i> {{ __('clients.portal_credentials') }}</h3>
            </div>
            <div class="card-body">
                <div class="staff-client-portal-box mb-3">
                    <div class="small text-muted mb-1">{{ __('clients.portal_username') }}</div>
                    <div class="fw-bold">{{ $client->username }}</div>
                    <div class="small text-muted mt-2 mb-1">{{ __('clients.portal_login_url') }}</div>
                    <code dir="ltr" class="d-block text-break">{{ $clientLoginUrl ?? client_portal_login_url() }}</code>
                </div>
                @if ($canImpersonate ?? auth()->user()->can('impersonate', $client))
                    <form method="POST" action="{{ route($panel.'.clients.impersonate', $client) }}">
                        @csrf
                        <button type="submit" class="btn btn-info w-100">
                            <i class="bx bx-door-open"></i> {{ __('clients.enter_portal') }}
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="panel-modern-card mb-3">
            <div class="card-head d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h3><i class="bx bx-credit-card"></i> {{ __('clients.owner_payment_cards') }}</h3>
                @if ($paymentCardEditRoute && Route::has($paymentCardEditRoute))
                    <a href="{{ route($paymentCardEditRoute) }}" class="btn btn-sm btn-outline-primary">{{ __('clients.manage_payment_card') }}</a>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted small">{{ __('clients.owner_payment_cards_hint') }}</p>
                @forelse ($paymentCards as $card)
                    <div class="border rounded-3 p-3 mb-2 bg-light">
                        @if ($card->bank_name)
                            <div class="small text-muted">{{ __('clients.bank_name') }}: {{ $card->bank_name }}</div>
                        @endif
                        <div class="staff-client-card-number my-2">{{ persian_digits($card->card_number) }}</div>
                        @if ($card->card_holder)
                            <div class="small">{{ __('clients.card_holder') }}: {{ $card->card_holder }}</div>
                        @endif
                    </div>
                @empty
                    <div class="alert alert-warning mb-0 small">
                        {{ __('clients.no_payment_cards_for_owner', ['role' => $ownerRoleLabel]) }}
                    </div>
                @endforelse
            </div>
        </div>

        <div class="panel-modern-card mb-3">
            <div class="card-head">
                <h3><i class="bx bx-money"></i> {{ __('clients.charge_requests_title') }}</h3>
            </div>
            <div class="card-body">
                @forelse ($paymentRequests as $paymentRequest)
                    @php [$prColor, $prLabel] = $paymentStatusLabel($paymentRequest->status); @endphp
                    <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom">
                        <div>
                            <div class="fw-semibold">{{ format_money($paymentRequest->amount, $paymentRequest->moneyCurrency()) }}</div>
                            <div class="text-muted small">{{ jalali_date($paymentRequest->created_at, 'Y/m/d H:i') }}</div>
                        </div>
                        <span class="badge bg-{{ $prColor }}">{{ $prLabel }}</span>
                    </div>
                @empty
                    <p class="text-muted small mb-0">—</p>
                @endforelse
                @if (Route::has($panel.'.payment-requests.index'))
                    <a href="{{ route($panel.'.payment-requests.index') }}" class="btn btn-sm btn-outline-secondary w-100 mt-2">{{ __('menu.payment_requests') }}</a>
                @endif
            </div>
        </div>

        <div class="panel-modern-card">
            <div class="card-head"><h3><i class="bx bx-history"></i> {{ __('clients.purchase_history') }}</h3></div>
            <div class="card-body">
                @forelse ($recentTransactions as $tx)
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <div>
                            <div class="fw-semibold small">{{ $txLabel($tx->type->value) }}</div>
                            <div class="text-muted small">{{ jalali_date($tx->created_at, 'Y/m/d H:i') }}</div>
                        </div>
                        <div class="fw-semibold @if((float)$tx->amount < 0) text-danger @else text-success @endif">
                            {{ format_money($tx->amount, $tx->moneyCurrency()) }}
                        </div>
                    </div>
                @empty
                    <p class="text-muted small mb-0">—</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
