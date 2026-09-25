@php
    $captcha = is_array($captcha ?? null) ? $captcha : [];
    $token = (string) ($captcha['token'] ?? '');
    $svg = (string) ($captcha['svg'] ?? '');
@endphp
<div class="mb-3" id="login-captcha-block">
    <label class="form-label" for="captcha-input">{{ __('auth.captcha_label') }}</label>
    <div class="d-flex align-items-stretch gap-2 mb-2">
        <div class="login-captcha-box flex-grow-1 border rounded bg-white overflow-hidden" dir="ltr">
            <span id="login-captcha-display" class="login-captcha-display @if ($svg === '') login-captcha-loading @endif" @if ($svg !== '') data-ready="1" @endif>
                {{-- نشانه‌گذاری SVG کامل سمت سرور و فقط از الفبای تصادفی خودِ LoginCaptchaService
                     ساخته می‌شود و هیچ ورودی کاربری در آن راه ندارد، پس چاپ خام آن بی‌خطر است.
                     پیش از این متنِ پاسخ چاپ می‌شد و کپچا هیچ رباتی را متوقف نمی‌کرد. --}}
                @if ($svg !== ''){!! $svg !!}@else{{ __('auth.captcha_loading') }}@endif
            </span>
        </div>
        <button type="button" class="btn btn-outline-secondary login-captcha-refresh-btn d-flex" id="login-captcha-refresh"
                title="{{ __('auth.captcha_refresh') }}" aria-label="{{ __('auth.captcha_refresh') }}">
            <svg class="login-captcha-refresh-icon" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
                <path d="M21 3v5h-5"/>
                <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
                <path d="M3 21v-5h5"/>
            </svg>
            <span class="login-captcha-refresh-label">{{ __('auth.captcha_refresh') }}</span>
        </button>
    </div>
    <input type="hidden" name="captcha_token" id="captcha-token" value="{{ $token }}">
    <div class="position-relative">
        <input type="text"
               class="form-control @error('captcha') is-invalid @enderror"
               id="captcha-input"
               name="captcha"
               value="{{ old('captcha') }}"
               autocomplete="off"
               autocapitalize="off"
               spellcheck="false"
               inputmode="text"
               maxlength="12"
               required
               placeholder="{{ __('auth.captcha_placeholder') }}">
    </div>
    <p class="form-text text-muted small mb-0">{{ __('auth.captcha_hint') }}</p>
    @error('captcha')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>
