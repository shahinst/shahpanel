@extends('layouts.auth')

@section('title', $title ?? __('security.two_factor_challenge'))

@section('content')
<div class="mb-4 pb-2 text-center">
    @include('auth.partials.brand-logo')
</div>

<div class="card">
    <div class="card-body p-4">
        <div class="text-center mt-2">
            <h5>{{ __('security.two_factor_challenge') }}</h5>
            <p class="text-muted">{{ app_display_name() }}</p>
            <p class="text-muted small">{{ __('security.two_factor_hint') }}</p>
        </div>
        <div class="p-2 mt-4">
            <form method="POST" action="{{ route('auth.two-factor.verify') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="code">{{ __('security.two_factor_code') }}</label>
                    <div class="position-relative input-custom-icon">
                        <input type="text" class="form-control text-center" id="code" name="code"
                               inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                               placeholder="000000" required autofocus autocomplete="one-time-code">
                        <span class="bx bx-shield-quarter"></span>
                    </div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-primary w-100 waves-effect waves-light" type="submit">{{ __('auth.login') }}</button>
                </div>
            </form>

            @if ($errors->any())
                <div class="alert alert-danger mt-3 mb-0">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
