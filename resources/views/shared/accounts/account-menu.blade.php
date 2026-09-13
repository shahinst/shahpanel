@props(['account', 'prefix'])

@php
    use App\Enums\ServiceType;
    use App\Support\SmsSettings;
    use App\Services\PackageCategoryService;
    $isWireguard = $account->service_type === ServiceType::Wireguard;
    $canShowDetails = in_array($prefix, ['admin', 'agent', 'seller'], true) && Route::has($prefix.'.accounts.show');
    $canTransfer = auth()->user()?->can('transferServer', $account) ?? false;
    $canRefund = (auth()->user()?->can('refund', $account) ?? false) && Route::has($prefix.'.accounts.refund');
    $canReactivate = $prefix === 'admin'
        && (auth()->user()?->can('reactivateAfterRefund', $account) ?? false)
        && Route::has('admin.accounts.reactivate');
    $canDelete = auth()->user()?->can('delete', $account) ?? false;
    $canEdit = in_array($prefix, ['admin', 'agent'], true) && Route::has($prefix.'.accounts.edit');
    $canSendLoginInfo = SmsSettings::isAccountLoginSmsEnabled()
        && in_array($prefix, ['admin', 'agent', 'seller'], true)
        && (auth()->user()?->can('sendLoginInfo', $account) ?? false);
    $canRenew = ! $account->isRefunded()
        && $account->package !== null
        && app(PackageCategoryService::class)->isPackageAvailableForRenewal($account->package);
@endphp

<div class="dropdown account-actions" data-account-actions>
    <button type="button" class="btn btn-light btn-sm" data-account-actions-toggle aria-expanded="false" aria-haspopup="true">
        <i class="bx bx-dots-vertical-rounded"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end account-actions-floating" data-account-actions-menu>
        @if ($canShowDetails)
            <li><a class="dropdown-item" href="{{ route($prefix.'.accounts.show', $account) }}"><i class="bx bx-show"></i> {{ __('accounts.show_details') }}</a></li>
            <li><hr class="dropdown-divider"></li>
        @endif
        @if ($isWireguard)
            <li><a class="dropdown-item" href="{{ route($prefix.'.accounts.config', $account) }}"><i class="bx bx-show"></i> {{ __('accounts.show_config') }}</a></li>
            <li><a class="dropdown-item" href="{{ route($prefix.'.accounts.config.download', $account) }}"><i class="bx bx-download"></i> {{ __('accounts.download_config') }}</a></li>
            <li><a class="dropdown-item" href="{{ route($prefix.'.accounts.config.qr', $account) }}"><i class="bx bx-qr"></i> {{ __('accounts.download_qr') }}</a></li>
            <li><hr class="dropdown-divider"></li>
        @endif
        @if ($canEdit)
            <li><a class="dropdown-item" href="{{ route($prefix.'.accounts.edit', $account) }}"><i class="bx bx-edit"></i> {{ __('app.edit') }}</a></li>
        @endif
        @if ($canTransfer)
            <li><a class="dropdown-item" href="{{ route($prefix.'.accounts.transfer-form', $account) }}"><i class="bx bx-transfer"></i> {{ __('accounts.change_server') }}</a></li>
        @endif
        @if ($canRenew)
            <li><a class="dropdown-item" href="{{ route($prefix.'.accounts.renew-form', $account) }}"><i class="bx bx-revision"></i> {{ __('menu.renew') }}</a></li>
        @endif
        @if ($canSendLoginInfo)
            <li><a class="dropdown-item" href="{{ route($prefix.'.accounts.send-login-info-form', $account) }}"><i class="bx bx-message-rounded-dots"></i> {{ __('accounts.send_login_info') }}</a></li>
        @endif
        @if ($canRefund)
            <li>
                @php
                    $refundOwnerName = $account->ownerSeller?->full_name ?? '—';
                    $refundConfirm = __('accounts.refund_owner_hint', ['owner' => $refundOwnerName])."\n\n".__('app.confirm');
                @endphp
                <form method="POST" action="{{ route($prefix.'.accounts.refund', $account) }}" onsubmit="return confirm(@json($refundConfirm))">
                    @csrf
                    <button type="submit" class="dropdown-item text-danger"><i class="bx bx-undo"></i> {{ __('accounts.refund') }}</button>
                </form>
            </li>
        @endif
        @if ($canReactivate)
            <li>
                @php
                    $reactivateOwnerName = $account->ownerSeller?->full_name ?? '—';
                    $reactivateConfirm = __('accounts.reactivate_confirm', ['owner' => $reactivateOwnerName]);
                @endphp
                <form method="POST" action="{{ route('admin.accounts.reactivate', $account) }}" onsubmit="return confirm(@json($reactivateConfirm))">
                    @csrf
                    <button type="submit" class="dropdown-item text-success"><i class="bx bx-revision"></i> {{ __('accounts.reactivate') }}</button>
                </form>
            </li>
        @endif
        @if ($canDelete && Route::has($prefix.'.accounts.destroy'))
            <li>
                <form method="POST" action="{{ route($prefix.'.accounts.destroy', $account) }}" onsubmit="return confirm('{{ __('accounts.delete_confirm') }}')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="dropdown-item text-danger"><i class="bx bx-trash"></i> {{ __('accounts.delete') }}</button>
                </form>
            </li>
        @endif
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="{{ route($prefix.'.accounts.portal-link', $account) }}" target="_blank"><i class="bx bx-link-external"></i> {{ __('menu.portal_link') }}</a></li>
    </ul>
</div>
