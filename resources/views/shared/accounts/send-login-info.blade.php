@extends('layouts.panel')

@section('page_title', __('accounts.send_login_info_title'))

@section('panel_content')
@php
    $sendDisabled = ! \App\Support\SmsSettings::isAccountLoginSmsEnabled()
        || ! $smsReady
        || $account->loginSmsSent();
@endphp

<p>
    <a href="{{ route($prefix.'.accounts.'.$account->service_type->accountCategory()->value) }}">
        <i class="bx bx-arrow-back"></i> {{ $account->service_type->accountCategory()->label() }}
    </a>
</p>

<x-card :title="__('accounts.send_login_info_title').' — '.$account->remote_username">
    @if ($account->loginSmsSent())
        <x-alert type="info" class="mb-3">{{ __('accounts.send_login_info_already_sent') }}</x-alert>
    @elseif (! \App\Support\SmsSettings::isAccountLoginSmsEnabled())
        <x-alert type="warning" class="mb-3">{{ __('sms.account_login_disabled') }}</x-alert>
    @elseif (! $smsReady)
        <x-alert type="warning" class="mb-3">{{ __('sms.account_sms_not_configured') }}</x-alert>
    @endif

    <p class="text-muted">{{ __('accounts.send_login_info_hint') }}</p>

    <div class="mb-3 small">
        <span class="text-muted d-block">{{ __('accounts.send_login_info_preview') }}</span>
        <pre class="bg-light p-2 rounded mb-1" style="white-space: pre-wrap;">{{ $previewMessage }}</pre>
        @if (! empty($portalLinkTtlMinutes))
            <p class="text-muted mb-0">{{ __('accounts.portal_link_sms_ttl_hint', ['minutes' => persian_digits($portalLinkTtlMinutes)]) }}</p>
        @endif
    </div>

    <form method="POST" action="{{ route($prefix.'.accounts.send-login-info', $account) }}">
        @csrf
        <x-form.group :label="__('sms.test_mobile')" :hint="__('accounts.send_login_info_mobile_hint')">
            <input type="text" name="mobile" class="form-control" dir="ltr" required
                   value="{{ old('mobile', $defaultMobile) }}" placeholder="09123456789"
                   @disabled($sendDisabled)>
        </x-form.group>

        <button type="submit" class="btn btn-primary" @disabled($sendDisabled)>
            <i class="bx bx-send"></i> {{ __('accounts.send_login_info_submit') }}
        </button>
    </form>
</x-card>
@endsection
