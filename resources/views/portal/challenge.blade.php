{{-- دروازهٔ کپچای صفحهٔ مشتری.
     این قالب هیچ متغیری از حساب نمی‌گیرد (نه نام کاربری، نه لینک اشتراک، نه
     کانفیگ) تا پیش از حل شدن کپچا هیچ داده‌ای در سورس صفحه نباشد؛ داده در
     درخواست بعدی و پس از تأیید سمت سرور می‌آید. --}}
@extends('layouts.portal')

@section('title', __('accounts.portal_captcha_title'))

@section('portal_content')
@php
    $captcha = is_array($captcha ?? null) ? $captcha : [];
    $captchaToken = (string) ($captcha['token'] ?? '');
    $captchaSvg = (string) ($captcha['svg'] ?? '');
@endphp
<div class="portal-wrap">
    <section class="portal-card portal-captcha-card">
        <div class="portal-captcha-icon" aria-hidden="true"><i class="bx bx-shield-quarter"></i></div>
        <h1 class="portal-captcha-title">{{ __('accounts.portal_captcha_title') }}</h1>
        <p class="portal-captcha-text">{{ __('accounts.portal_captcha_intro') }}</p>

        <form method="POST" action="{{ route('portal.verify', $portalToken) }}" class="portal-captcha-form">
            @csrf

            <div class="portal-captcha-row">
                <div class="portal-captcha-image" dir="ltr">
                    <span id="portal-captcha-display"
                          class="@if ($captchaSvg === '') portal-captcha-loading @endif"
                          @if ($captchaSvg !== '') data-ready="1" @endif>
                        {{-- این SVG کامل سمت سرور و از الفبای تصادفی خودِ LoginCaptchaService
                             ساخته می‌شود و هیچ ورودی کاربری در آن راه ندارد، پس چاپ خامش
                             بی‌خطر است. پاسخ کپچا هرگز به مرورگر فرستاده نمی‌شود. --}}
                        @if ($captchaSvg !== ''){!! $captchaSvg !!}@else{{ __('auth.captcha_loading') }}@endif
                    </span>
                </div>
                <button type="button" class="portal-captcha-refresh" id="portal-captcha-refresh"
                        title="{{ __('auth.captcha_refresh') }}" aria-label="{{ __('auth.captcha_refresh') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
                        <path d="M21 3v5h-5"/>
                        <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
                        <path d="M3 21v-5h5"/>
                    </svg>
                </button>
            </div>

            <label class="portal-captcha-label" for="portal-captcha-input">{{ __('auth.captcha_label') }}</label>
            <input type="hidden" name="captcha_token" id="portal-captcha-token" value="{{ $captchaToken }}">
            <input type="text"
                   class="portal-captcha-input"
                   id="portal-captcha-input"
                   name="captcha"
                   value=""
                   autocomplete="off"
                   autocapitalize="off"
                   autocorrect="off"
                   spellcheck="false"
                   inputmode="text"
                   maxlength="12"
                   required
                   placeholder="{{ __('auth.captcha_placeholder') }}">

            @error('captcha')
                <p class="portal-captcha-error">{{ $message }}</p>
            @enderror

            <p class="portal-captcha-hint">{{ __('auth.captcha_hint') }}</p>

            <button type="submit" class="portal-action-btn portal-action-btn--primary portal-captcha-submit">
                {{ __('accounts.portal_captcha_submit') }}
            </button>
        </form>
    </section>
    <footer class="portal-footer">{{ config('app.name') }}</footer>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        var refreshBtn = document.getElementById('portal-captcha-refresh');
        var displayEl = document.getElementById('portal-captcha-display');
        var tokenInput = document.getElementById('portal-captcha-token');
        var answerInput = document.getElementById('portal-captcha-input');

        if (!refreshBtn || !displayEl || !tokenInput) {
            return;
        }

        var refreshUrl = @json(route('portal.captcha', $portalToken));
        var loadingText = @json(__('auth.captcha_loading'));
        var loadErrorText = @json(__('auth.captcha_load_failed'));

        function loadCaptcha() {
            refreshBtn.disabled = true;
            displayEl.className = 'portal-captcha-loading';
            displayEl.textContent = loadingText;

            fetch(refreshUrl, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('captcha');
                }

                return response.json();
            }).then(function (data) {
                // پاسخ کپچا در این JSON نیست؛ فقط تصویر و شناسه‌اش می‌آید.
                displayEl.className = '';
                displayEl.innerHTML = data.svg;
                displayEl.setAttribute('data-ready', '1');
                tokenInput.value = data.token;

                if (answerInput) {
                    answerInput.value = '';
                    answerInput.focus();
                }
            }).catch(function () {
                displayEl.className = 'portal-captcha-loading';
                displayEl.textContent = loadErrorText;
            }).finally(function () {
                refreshBtn.disabled = false;
            });
        }

        refreshBtn.addEventListener('click', loadCaptcha);

        // تصویر اول را سرور رندر کرده؛ اینجا فقط اگر خالی بود دنبالش می‌رویم.
        if (!displayEl.getAttribute('data-ready')) {
            loadCaptcha();
        }
    })();
</script>
@endpush
