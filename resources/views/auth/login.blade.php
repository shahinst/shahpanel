@extends('layouts.auth')

@section('title', $title ?? __('auth.login'))

@section('content')
@php
    $captcha = $captcha ?? session('loginCaptcha');
    if (! is_array($captcha)) {
        $captcha = [];
    }
@endphp
<div class="mb-4 pb-2 text-center">
    @include('auth.partials.brand-logo')
</div>

<div class="card">
    <div class="card-body p-4">
        <div class="text-center mt-2">
            <h5>{{ $title ?? __('auth.login') }}</h5>
            <p class="text-muted mb-1">{{ app_display_name() }}</p>
            @if ($unified ?? false)
                <p class="text-muted small">{{ __('auth.login_subtitle') }}</p>
            @endif
        </div>
        <div class="p-2 mt-4">
            <form method="POST" action="{{ url('/login') }}" id="login-form" autocomplete="off">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="username">{{ __('auth.username') }}</label>
                    <input type="text" class="form-control" id="username" name="username"
                           value="{{ old('username') }}" placeholder="{{ __('auth.username') }}" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password-input">{{ __('auth.password') }}</label>
                    <input type="password" class="form-control" id="password-input" name="password"
                           placeholder="{{ __('auth.password') }}" required>
                </div>
                <div class="form-check py-1">
                    <input type="checkbox" class="form-check-input" id="auth-remember-check" name="remember" value="1"
                           @checked(old('remember'))>
                    <label class="form-check-label" for="auth-remember-check">{{ __('auth.remember_me') }}</label>
                </div>

                @include('auth.partials.login-captcha', ['captcha' => $captcha])

                <div class="mt-3">
                    <button class="btn btn-primary w-100" type="submit">{{ __('auth.login') }}</button>
                </div>
            </form>

            @if ($errors->any() && ! $errors->has('captcha'))
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

@push('scripts')
<script>
(function () {
    var refreshBtn = document.getElementById('login-captcha-refresh');
    var displayEl = document.getElementById('login-captcha-display');
    var tokenInput = document.getElementById('captcha-token');
    var captchaInput = document.getElementById('captcha-input');
    var refreshUrl = @json(url('/login/captcha'));
    var loadingText = @json(__('auth.captcha_loading'));
    var loadErrorText = @json(__('auth.captcha_load_failed'));

    if (!displayEl || !tokenInput) {
        return;
    }

    function applyCaptcha(data, clearInput) {
        if (data && data.display) {
            displayEl.textContent = data.display;
            displayEl.classList.remove('login-captcha-loading');
            displayEl.setAttribute('data-ready', '1');
        }
        if (data && data.token) {
            tokenInput.value = data.token;
        }
        if (clearInput && captchaInput) {
            captchaInput.value = '';
        }
    }

    function loadCaptcha(clearInput) {
        if (refreshBtn) {
            refreshBtn.disabled = true;
        }
        displayEl.textContent = loadingText;
        displayEl.classList.add('login-captcha-loading');
        displayEl.removeAttribute('data-ready');

        fetch(refreshUrl, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.json();
            })
            .then(function (data) {
                applyCaptcha(data, clearInput);
            })
            .catch(function () {
                displayEl.textContent = loadErrorText;
                displayEl.classList.add('login-captcha-loading', 'text-danger');
            })
            .finally(function () {
                if (refreshBtn) {
                    refreshBtn.disabled = false;
                }
            });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            loadCaptcha(true);
        });
    }

    /* Server already rendered captcha — do not replace fields on load */
})();
</script>
@endpush
