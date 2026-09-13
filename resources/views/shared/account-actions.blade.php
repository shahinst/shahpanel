@props(['account', 'prefix'])

@php
    use App\Support\SmsSettings;
    use App\Services\PackageCategoryService;
    $canDelete = auth()->user()?->can('delete', $account) ?? false;
    $canRefund = (auth()->user()?->can('refund', $account) ?? false) && Route::has($prefix.'.accounts.refund');
    $canReactivate = $prefix === 'admin'
        && (auth()->user()?->can('reactivateAfterRefund', $account) ?? false)
        && Route::has('admin.accounts.reactivate');
    $canSendLoginInfo = SmsSettings::isAccountLoginSmsEnabled()
        && in_array($prefix, ['admin', 'agent', 'seller'], true)
        && (auth()->user()?->can('sendLoginInfo', $account) ?? false);
    $canRenew = ! $account->isRefunded()
        && $account->package !== null
        && app(PackageCategoryService::class)->isPackageAvailableForRenewal($account->package);
@endphp

@if (in_array($prefix, ['admin', 'agent'], true) && Route::has($prefix.'.accounts.edit'))
    <a href="{{ route($prefix.'.accounts.edit', $account) }}" class="btn btn-sm btn-light">{{ __('app.edit') }}</a>
@endif
@if ($canRenew && Route::has($prefix.'.accounts.renew-form'))
    <a href="{{ route($prefix.'.accounts.renew-form', $account) }}" class="btn btn-sm btn-primary">{{ __('menu.renew') }}</a>
@elseif ($canRenew)
<form method="POST" action="{{ route($prefix.'.accounts.renew', $account) }}" style="display:inline;" onsubmit="return confirm('{{ __('app.confirm') }}')">
    @csrf
    <button type="submit" class="btn btn-sm btn-primary">{{ __('menu.renew') }}</button>
</form>
@endif
@if ($canSendLoginInfo)
    <a href="{{ route($prefix.'.accounts.send-login-info-form', $account) }}" class="btn btn-sm btn-secondary">{{ __('accounts.send_login_info') }}</a>
@endif
@if (! $account->isRefunded())
    @if ($account->status->value === 'active')
        <form method="POST" action="{{ route($prefix.'.accounts.disable', $account) }}" style="display:inline;" onsubmit="return confirm('{{ __('app.confirm') }}')">
            @csrf
            <button type="submit" class="btn btn-sm btn-warning">{{ __('menu.disable') }}</button>
        </form>
    @else
        <form method="POST" action="{{ route($prefix.'.accounts.enable', $account) }}" style="display:inline;">
            @csrf
            <button type="submit" class="btn btn-sm btn-success">{{ __('menu.enable') }}</button>
        </form>
    @endif
@endif
@if (Route::has($prefix.'.accounts.portal-link'))
    <a href="{{ route($prefix.'.accounts.portal-link', $account) }}" class="btn btn-sm btn-info" target="_blank">{{ __('menu.portal_link') }}</a>
@endif
@if ($canRefund)
    @php
        $refundOwnerName = $account->ownerSeller?->full_name ?? '—';
        $refundConfirm = __('accounts.refund_owner_hint', ['owner' => $refundOwnerName])."\n\n".__('app.confirm');
    @endphp
    <form method="POST" action="{{ route($prefix.'.accounts.refund', $account) }}" style="display:inline;" onsubmit="return confirm(@json($refundConfirm))">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bx bx-undo"></i> {{ __('accounts.refund') }}</button>
    </form>
@endif
@if ($canReactivate)
    @php
        $reactivateOwnerName = $account->ownerSeller?->full_name ?? '—';
        $reactivateConfirm = __('accounts.reactivate_confirm', ['owner' => $reactivateOwnerName]);
    @endphp
    <form method="POST" action="{{ route('admin.accounts.reactivate', $account) }}" style="display:inline;" onsubmit="return confirm(@json($reactivateConfirm))">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-success"><i class="bx bx-revision"></i> {{ __('accounts.reactivate') }}</button>
    </form>
@endif
@if ($canDelete && Route::has($prefix.'.accounts.destroy'))
    <form method="POST" action="{{ route($prefix.'.accounts.destroy', $account) }}" style="display:inline;" onsubmit="return confirm('{{ __('accounts.delete_confirm') }}')">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-sm btn-danger">{{ __('accounts.delete') }}</button>
    </form>
@endif
