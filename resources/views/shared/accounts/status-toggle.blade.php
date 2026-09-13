@props(['account', 'prefix'])

@php
    $isActive = $account->status->value === 'active';
    $canToggle = ! $account->isRefunded()
        && in_array($account->status->value, ['active', 'disabled'], true)
        && ! $account->isExpired()
        && ! $account->isQuotaExhausted();
    $statusLabel = match (true) {
        $account->isRefunded() => __('accounts.status_refunded'),
        $account->status->value === 'active' => __('accounts.status_active'),
        $account->status->value === 'disabled' => __('accounts.status_disabled'),
        $account->status->value === 'expired' => __('accounts.status_expired'),
        $account->status->value === 'exhausted' => __('accounts.status_exhausted'),
        default => $account->status->value,
    };
@endphp

@if ($canToggle)
    <form method="POST" action="{{ route($prefix.'.accounts.'.($isActive ? 'disable' : 'enable'), $account) }}" style="display:inline;">
        @csrf
        <button type="submit" class="btn btn-sm {{ $isActive ? 'btn-success' : 'btn-warning' }}" onclick="return confirm('{{ __('app.confirm') }}')">
            {{ $statusLabel }}
        </button>
    </form>
@else
    @php
        $labelColor = match (true) {
            $account->isRefunded() => 'default',
            $isActive => 'success',
            $account->status->value === 'disabled' => 'warning',
            default => 'default',
        };
    @endphp
    <x-badge :label="$statusLabel" :color="$labelColor" />
@endif
