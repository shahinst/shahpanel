@extends('layouts.panel')

@section('page_title', __('clients.portal_title'))

@section('panel_content')
@php
    use App\Enums\AccountStatus;
    use App\Enums\PaymentRequestStatus;

    $activeAccounts = $accounts->filter(fn ($a) => $a->status === AccountStatus::Active)->count();
    $pendingChargeCount = $recentPayments->where('status', PaymentRequestStatus::Pending)->count();
    $previewAccounts = $accounts->take(6);

    $statusBadge = fn (AccountStatus $status): array => match ($status) {
        AccountStatus::Active => ['success', __('accounts.status_active')],
        AccountStatus::Disabled => ['secondary', __('accounts.status_disabled')],
        AccountStatus::Expired => ['danger', __('accounts.status_expired')],
        AccountStatus::Exhausted => ['warning', __('accounts.status_exhausted')],
        AccountStatus::Pending => ['info', __('accounts.status_pending')],
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

    $paymentStatusLabel = fn (PaymentRequestStatus $status): array => match ($status) {
        PaymentRequestStatus::Pending => ['warning', __('clients.payment_pending')],
        PaymentRequestStatus::Approved => ['success', __('clients.payment_approved')],
        PaymentRequestStatus::Rejected => ['danger', __('clients.payment_rejected')],
    };
@endphp

@push('styles')
<style>
.client-dash-hero {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 55%, #1e40af 100%);
    border-radius: 16px;
    color: #fff;
    padding: 1.5rem 1.75rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 10px 30px rgba(37, 99, 235, 0.18);
}
.client-dash-hero h2 { color: #fff; font-size: 1.35rem; margin-bottom: .35rem; }
.client-dash-hero p { color: rgba(255,255,255,.88); margin-bottom: 0; }
.client-quick-action {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.15rem;
    border-radius: 14px;
    border: 1px solid #e9ecef;
    background: #fff;
    text-decoration: none;
    color: inherit;
    height: 100%;
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
}
.client-quick-action:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
    border-color: #cfe2ff;
    color: inherit;
}
.client-quick-action .icon-wrap {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
}
.client-quick-action .action-title { font-weight: 600; margin-bottom: .15rem; }
.client-quick-action .action-desc { font-size: .82rem; color: #64748b; margin: 0; }
.client-wallet-card {
    border: 1px dashed #93c5fd;
    background: linear-gradient(180deg, #eff6ff 0%, #fff 100%);
    border-radius: 16px;
}
.client-wallet-card .card-number {
    letter-spacing: .12em;
    font-size: 1.25rem;
    font-weight: 700;
    direction: ltr;
    text-align: center;
    padding: .85rem 1rem;
    background: #fff;
    border-radius: 12px;
    border: 1px solid #dbeafe;
}
.client-account-card {
    border-radius: 14px;
    border: 1px solid #e9ecef;
    padding: 1rem;
    height: 100%;
    background: #fff;
    transition: box-shadow .15s ease;
}
.client-account-card:hover { box-shadow: 0 6px 20px rgba(15, 23, 42, .06); }
.client-account-card .username {
    font-weight: 700;
    font-size: .95rem;
    word-break: break-all;
}
.client-timeline-item {
    display: flex;
    justify-content: space-between;
    gap: .75rem;
    padding: .75rem 0;
    border-bottom: 1px solid #f1f5f9;
}
.client-timeline-item:last-child { border-bottom: 0; padding-bottom: 0; }
.client-empty-state {
    text-align: center;
    padding: 2rem 1rem;
    color: #64748b;
}
.client-empty-state i { font-size: 2.5rem; color: #cbd5e1; display: block; margin-bottom: .75rem; }
</style>
@endpush

<div class="client-dash-hero">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h2>{{ __('clients.dashboard_welcome', ['name' => auth()->user()->full_name]) }}</h2>
            <p>{{ __('clients.dashboard_subtitle') }}</p>
        </div>
        <div class="text-start text-md-end">
            <div class="small opacity-75">{{ __('clients.owner') }}</div>
            <div class="fw-semibold">{{ $owner?->full_name ?? '—' }}</div>
        </div>
    </div>
</div>

<div class="row">
    <x-stat-card :title="__('clients.wallet_balance')" :value="format_money($wallet->balance, $wallet->moneyCurrency())" icon="bx-wallet" color="success" />
    <x-stat-card :title="__('clients.my_accounts')" :value="persian_digits($accounts->count())" icon="bx-server" color="primary" />
    <x-stat-card :title="__('clients.active_accounts')" :value="persian_digits($activeAccounts)" icon="bx-check-shield" color="success" />
    <x-stat-card :title="__('clients.pending_charges')" :value="persian_digits($pendingChargeCount)" icon="bx-time-five" color="warning" />
</div>

<div class="mb-4 mt-2">
    <h5 class="mb-3 text-muted">{{ __('clients.quick_actions') }}</h5>
    <div class="row g-3">
        <div class="col-md-4">
            <a href="{{ route('client.shop.index') }}" class="client-quick-action">
                <div class="icon-wrap bg-soft-primary text-primary"><i class="bx bx-cart"></i></div>
                <div>
                    <div class="action-title">{{ __('clients.buy_account') }}</div>
                    <p class="action-desc">{{ __('clients.quick_buy_desc') }}</p>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('client.payment-requests.create') }}" class="client-quick-action">
                <div class="icon-wrap bg-soft-success text-success"><i class="bx bx-credit-card"></i></div>
                <div>
                    <div class="action-title">{{ __('clients.charge_wallet') }}</div>
                    <p class="action-desc">{{ __('clients.quick_charge_desc') }}</p>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('client.accounts.index') }}" class="client-quick-action">
                <div class="icon-wrap bg-soft-warning text-warning"><i class="bx bx-list-ul"></i></div>
                <div>
                    <div class="action-title">{{ __('clients.my_accounts') }}</div>
                    <p class="action-desc">{{ __('clients.quick_accounts_desc') }}</p>
                </div>
            </a>
        </div>
    </div>
</div>

@if ($owner)
@include('client.partials.payment-card-box', [
    'owner' => $owner,
    'ownerRoleLabel' => $ownerRoleLabel,
    'cards' => $paymentCards,
    'showActions' => true,
])

@if ($paymentCards->isNotEmpty())
    <div class="mb-4">
        <x-button :href="route('client.payment-requests.create')">{{ __('clients.charge_wallet') }}</x-button>
    </div>
@endif
@endif

<div class="row g-4">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h4 class="card-title mb-0">{{ __('clients.my_accounts') }}</h4>
                @if ($accounts->isNotEmpty())
                    <a href="{{ route('client.accounts.index') }}" class="btn btn-sm btn-light">{{ __('clients.view_all_accounts') }}</a>
                @endif
            </div>
            <div class="card-body">
                @if ($previewAccounts->isEmpty())
                    <div class="client-empty-state">
                        <i class="bx bx-server"></i>
                        <p class="mb-3">{{ __('clients.no_accounts') }}</p>
                        <x-button :href="route('client.shop.index')">{{ __('clients.buy_account') }}</x-button>
                    </div>
                @else
                    <div class="row g-3">
                        @foreach ($previewAccounts as $account)
                            @php [$badgeColor, $badgeLabel] = $statusBadge($account->status); @endphp
                            <div class="col-md-6">
                                <div class="client-account-card">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <div class="username">{{ $account->remote_username }}</div>
                                        <span class="badge bg-{{ $badgeColor }}">{{ $badgeLabel }}</span>
                                    </div>
                                    <div class="text-muted small mb-2">{{ $account->package?->name ?? '—' }}</div>

                                    <div class="small mb-2">
                                        <span class="text-muted">{{ __('clients.expires_in') }}:</span>
                                        {{ $account->expiry_at ? jalali_date($account->expiry_at) : __('accounts.no_expiry') }}
                                    </div>

                                    @if ($account->isUnlimited())
                                        <div class="small text-muted mb-3">{{ __('clients.unlimited_data') }}</div>
                                    @elseif ($account->data_limit_bytes)
                                        @php
                                            $used = min(100, round(($account->data_used_bytes / max(1, $account->data_limit_bytes)) * 100, 1));
                                        @endphp
                                        <div class="small text-muted mb-1">{{ __('clients.data_usage') }}</div>
                                        <div class="progress mb-3" style="height: 8px;">
                                            <div class="progress-bar @if($used >= 90) bg-danger @elseif($used >= 70) bg-warning @else bg-primary @endif"
                                                 style="width: {{ $used }}%"></div>
                                        </div>
                                        <div class="small text-muted mb-3">{{ format_data_size($account->data_used_bytes) }} / {{ format_data_size($account->data_limit_bytes) }}</div>
                                    @endif

                                    <x-button size="sm" :href="route('client.accounts.show', $account)" class="w-100">
                                        {{ __('clients.view_details') }}
                                    </x-button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <x-card :title="__('clients.recent_charge_requests')" class="mb-4">
            @forelse ($recentPayments as $paymentRequest)
                @php [$prColor, $prLabel] = $paymentStatusLabel($paymentRequest->status); @endphp
                <div class="client-timeline-item">
                    <div>
                        <div class="fw-semibold">{{ format_money($paymentRequest->amount, $paymentRequest->moneyCurrency()) }}</div>
                        <div class="text-muted small">{{ jalali_date($paymentRequest->created_at, 'Y/m/d H:i') }}</div>
                    </div>
                    <span class="badge bg-{{ $prColor }} align-self-start">{{ $prLabel }}</span>
                </div>
            @empty
                <div class="client-empty-state py-3">
                    <p class="mb-2 small">{{ __('clients.no_charge_requests') }}</p>
                    <x-button size="sm" :href="route('client.payment-requests.create')">{{ __('clients.charge_wallet') }}</x-button>
                </div>
            @endforelse
            @if ($recentPayments->isNotEmpty())
                <div class="mt-2 text-center">
                    <a href="{{ route('client.payment-requests.index') }}" class="small">{{ __('menu.payment_requests') }}</a>
                </div>
            @endif
        </x-card>

        <x-card :title="__('clients.purchase_history')">
            @forelse ($recentTransactions as $tx)
                <div class="client-timeline-item">
                    <div>
                        <div class="fw-semibold">{{ $txLabel($tx->type->value) }}</div>
                        <div class="text-muted small">{{ jalali_date($tx->created_at, 'Y/m/d H:i') }}</div>
                    </div>
                    <div class="fw-semibold @if((float)$tx->amount < 0) text-danger @else text-success @endif">
                        {{ format_money($tx->amount, $tx->moneyCurrency()) }}
                    </div>
                </div>
            @empty
                <p class="text-muted small mb-0 text-center py-3">{{ __('clients.no_transactions') }}</p>
            @endforelse
        </x-card>
    </div>
</div>
@endsection
