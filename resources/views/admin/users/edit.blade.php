@extends('layouts.panel')

@section('page_title', __('menu.agents'))

@section('panel_content')
<x-card>
    <form method="POST" action="{{ route('admin.users.update', $user) }}">
        @csrf
        @method('PUT')
        <div class="row">
            @include('admin.users._form', ['user' => $user])
            @include('shared.users._wallet_readonly', ['wallets' => $user->wallets ?? collect([$wallet ?? null])->filter()])
            <x-form.actions>
                <x-button type="submit">{{ __('app.save') }}</x-button>
                <x-button :href="route('admin.users.index')" variant="secondary">{{ __('app.cancel') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</x-card>

@can('disableTwoFactor', $user)
    @if (app(\App\Services\TwoFactorService::class)->isEnabled($user))
        <x-card class="mt-3">
            <form method="POST" action="{{ route('admin.users.two-factor.disable', $user) }}"
                  onsubmit="return confirm('{{ __('security.two_factor_disable_admin') }}?')">
                @csrf
                <p class="text-muted mb-2">{{ __('security.two_factor') }}: {{ __('security.two_factor_already_enabled') }}</p>
                <button type="submit" class="btn btn-warning btn-sm">{{ __('security.two_factor_disable_admin') }}</button>
            </form>
        </x-card>
    @endif
@endcan
@endsection
