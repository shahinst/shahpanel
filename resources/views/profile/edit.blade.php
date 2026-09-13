@extends('layouts.panel')

@php
    $routeName = request()->route()?->getName() ?? '';
    $panel = explode('.', $routeName)[0] ?: 'admin';
    $profileUpdateRoute = $panel.'.profile.update';
    $dashboardRoute = $panel.'.dashboard';
    $twoFactorRoute = $panel.'.two-factor.show';
@endphp

@section('page_title', __('profile.title'))

@section('panel_content')
<x-page-header :title="__('profile.title')" />
<p class="text-muted mb-4">{{ __('profile.subtitle') }}</p>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <span class="badge bg-soft-primary text-primary">{{ $user->role->label() }}</span>
                    <span class="badge bg-soft-secondary text-secondary">{{ $user->username }}</span>
                </div>

                <form method="POST" action="{{ route($profileUpdateRoute) }}">
                    @csrf
                    @method('PUT')
                    <div class="row">
                        @include('profile._form', ['user' => $user])
                    </div>
                    <x-form.actions>
                        <x-button type="submit">{{ __('app.save') }}</x-button>
                        <x-button :href="route($dashboardRoute)" variant="secondary">{{ __('app.cancel') }}</x-button>
                    </x-form.actions>
                </form>
            </div>
        </div>

        @if (\Illuminate\Support\Facades\Route::has($twoFactorRoute))
            <div class="card mt-3">
                <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h6 class="mb-1">{{ __('security.two_factor') }}</h6>
                        <p class="text-muted small mb-0">{{ __('profile.two_factor_link_hint') }}</p>
                    </div>
                    <a href="{{ route($twoFactorRoute) }}" class="btn btn-outline-primary btn-sm">
                        {{ __('security.two_factor') }}
                    </a>
                </div>
            </div>
        @endif
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h6 class="mb-3">{{ __('profile.account_info') }}</h6>
                <dl class="mb-0 small">
                    <dt class="text-muted">{{ __('profile.last_login') }}</dt>
                    <dd>{{ $user->last_login_at ? jalali_date($user->last_login_at, 'Y/m/d H:i') : '—' }}</dd>
                    <dt class="text-muted">{{ __('profile.last_login_ip') }}</dt>
                    <dd dir="ltr">{{ $user->last_login_ip ?? '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection
