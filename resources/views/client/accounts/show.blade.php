@extends('layouts.panel')

@section('page_title', $account->remote_username)

@section('panel_content')
<div class="row">
    <div class="col-lg-8">
        <x-card :title="__('accounts.portal_details')">
            <dl class="row mb-0">
                <dt class="col-sm-4">{{ __('accounts.package') }}</dt>
                <dd class="col-sm-8">{{ $account->package?->name }}</dd>
                <dt class="col-sm-4">{{ __('accounts.expires_at') }}</dt>
                <dd class="col-sm-8">{{ $account->expiry_at ? jalali_date($account->expiry_at) : __('accounts.no_expiry') }}</dd>
                <dt class="col-sm-4">{{ __('accounts.total_usage') }}</dt>
                <dd class="col-sm-8" id="usage-total">{{ format_data_size($account->data_used_bytes) }}</dd>
            </dl>
            <p class="text-muted small mt-3 mb-0">{{ __('accounts.portal_link_staff_only_hint') }}</p>
        </x-card>
    </div>
    @if (($canRenew ?? false) && $renewalPrice !== null && ! $account->isRefunded())
    <div class="col-lg-4">
        <x-card :title="__('clients.renew_account')">
            <p>{{ __('clients.renew_price') }}: <strong>{{ format_money($renewalPrice, $renewalCurrency) }}</strong></p>
            <form method="POST" action="{{ route('client.accounts.renew', $account) }}">
                @csrf
                <x-button type="submit">{{ __('menu.renew') }}</x-button>
            </form>
        </x-card>
    </div>
    @endif
</div>
@endsection
