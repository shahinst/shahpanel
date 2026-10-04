@extends('layouts.panel')

@section('page_title', __('shahbot::admin.my_bot'))

@php $prefix = $panel.'.shahbot.my-bot'; @endphp

@section('panel_content')
    <div class="alert alert-info alert-dismissible" id="bot-guide" hidden>
        <h6 class="mb-2"><i class="bx bx-bulb"></i> {{ __('shahbot::admin.bot_guide_title') }}</h6>
        <div class="small">{!! nl2br(e(__('shahbot::admin.bot_guide'))) !!}</div>
        <button type="button" class="btn-close" aria-label="close"
                onclick="localStorage.setItem('shahbot-guide-closed', '1'); this.parentElement.hidden = true;"></button>
    </div>
    <script>
        // Shown until the reseller closes it; remembered on this browser.
        if (localStorage.getItem('shahbot-guide-closed') !== '1') document.getElementById('bot-guide').hidden = false;
    </script>
<x-page-header :title="__('shahbot::admin.my_bot')">
    <p class="text-muted mb-0">{{ __('shahbot::admin.my_bot_subtitle') }}</p>
    <a href="{{ route($prefix.'.plans') }}" class="btn btn-outline-primary"><i class="bx bx-package"></i> {{ __('shahbot::admin.my_plans') }}</a>
    <a href="{{ route($panel.'.shahbot.customers') }}" class="btn btn-outline-primary"><i class="bx bx-group"></i> {{ __('shahbot::admin.customers') }}</a>
    @if ($panel === 'agent')
        <a href="{{ route('agent.shahbot.access') }}" class="btn btn-outline-primary"><i class="bx bx-key"></i> {{ __('shahbot::admin.bot_access_my_sellers') }}</a>
    @endif
    @if ($bot && $bot->is_active && $bot->token() !== '')
        <a href="#my-broadcast" class="btn btn-outline-secondary"><i class="bx bx-broadcast"></i> {{ __('shahbot::admin.my_broadcast') }}</a>
    @endif
</x-page-header>

@if (! $enabled)
    <x-alert type="warning">{{ __('shahbot::admin.my_bot_disabled') }}</x-alert>
@else
    @if ($bot && ! $bot->is_active)
        <x-alert type="error">{{ __('shahbot::admin.my_bot_suspended') }}</x-alert>
    @endif

    @if ($stats)
        <div class="row g-3 mb-3">
            <x-stat-card :title="__('shahbot::admin.bot_users')" :value="persian_digits($stats['users'])" icon="bx-group" />
            <x-stat-card :title="__('shahbot::admin.bot_sales')" :value="format_money($stats['sales'])" icon="bx-cart" color="green" />
            @if ($bot->username)
                <div class="col-xl-3 col-md-6 d-flex align-items-center"><a href="https://t.me/{{ $bot->username }}" target="_blank" rel="noopener" class="btn btn-outline-primary"><i class="bx bxl-telegram"></i> {{ '@'.$bot->username }}</a></div>
            @endif
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <p class="text-muted">{{ __('shahbot::admin.my_bot_steps') }}</p>
            <form method="POST" action="{{ route($prefix.'.update') }}" class="row g-3" enctype="multipart/form-data">
                @csrf
                    <div class="col-12"><hr><h6 class="mb-2"><i class="bx bx-bitcoin"></i> {{ __('shahbot::admin.np_title') }}</h6>
                        <div class="alert alert-info small mb-2">{!! nl2br(e(__('shahbot::admin.np_guide'))) !!}</div>
                        @if ($bot)
                            <label class="form-label small">{{ __('shahbot::admin.np_callback') }}</label>
                            <input type="text" readonly dir="ltr" class="form-control form-control-sm mb-2" value="{{ app(\Modules\ShahBot\Services\ResellerCryptoService::class)->callbackUrl($bot) }}" onclick="this.select()">
                        @endif
                    </div>
                    <div class="col-md-6"><label class="form-label">{{ __('shahbot::admin.np_api_key') }}</label>
                        <input type="password" name="np_api_key" dir="ltr" autocomplete="off" class="form-control" placeholder="{{ $bot && app(\Modules\ShahBot\Services\ResellerCryptoService::class)->configured($bot) ? __('shahbot::admin.np_saved') : '' }}"></div>
                    <div class="col-md-6"><label class="form-label">{{ __('shahbot::admin.np_ipn_secret') }}</label>
                        <input type="password" name="np_ipn_secret" dir="ltr" autocomplete="off" class="form-control"></div>
                    <div class="col-12"><label class="form-check"><input type="checkbox" name="np_clear" value="1" class="form-check-input"> {{ __('shahbot::admin.np_clear') }}</label></div>
                <div class="col-12">
                    <label class="form-label">{{ __('shahbot::admin.brand_name') }}</label>
                    <input type="text" name="brand_name" value="{{ old('brand_name', $values['brand_name'] ?? '') }}" class="form-control" maxlength="80">
                    <div class="text-muted small">{{ __('shahbot::admin.brand_name_hint') }}</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('shahbot::admin.about_text') }}</label>
                    <textarea name="about_text" rows="4" class="form-control">{{ old('about_text', $values['about_text'] ?? '') }}</textarea>
                    <div class="text-muted small">{{ __('shahbot::admin.about_hint') }}</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('shahbot::admin.contact_text') }}</label>
                    <textarea name="contact_text" rows="4" class="form-control">{{ old('contact_text', $values['contact_text'] ?? '') }}</textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">{{ __('shahbot::admin.faq_text') }}</label>
                    <textarea name="faq_text" rows="3" class="form-control">{{ old('faq_text', $values['faq_text'] ?? '') }}</textarea>
                    <div class="text-muted small">{{ __('shahbot::admin.faq_hint') }}</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('shahbot::admin.bot_token') }}</label>
                    <input type="password" name="bot_token" class="form-control" dir="ltr" autocomplete="off" placeholder="{{ $bot && $bot->token() !== '' ? '••••••••••' : '123456:ABC...' }}">
                    @if ($bot && $bot->token() !== '')<div class="text-muted small mt-1">{{ __('shahbot::admin.bot_token_saved') }}</div>@endif
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('shahbot::admin.admin_chat_ids') }}</label>
                    <textarea name="admin_chat_ids" rows="2" class="form-control" dir="ltr">{{ old('admin_chat_ids', $values['admin_chat_ids'] ?? '') }}</textarea>
                </div>
                <div class="col-md-4"><label class="form-label">{{ __('shahbot::admin.card_number') }}</label><input type="text" name="card_number" value="{{ old('card_number', $values['card_number'] ?? '') }}" class="form-control" dir="ltr"></div>
                <div class="col-md-4"><label class="form-label">{{ __('shahbot::admin.card_holder') }}</label><input type="text" name="card_holder" value="{{ old('card_holder', $values['card_holder'] ?? '') }}" class="form-control"></div>
                <div class="col-md-4"><label class="form-label">{{ __('shahbot::admin.card_bank') }}</label><input type="text" name="card_bank" value="{{ old('card_bank', $values['card_bank'] ?? '') }}" class="form-control"></div>
                <div class="col-12"><label class="form-label">{{ __('shahbot::admin.card_note') }}</label><input type="text" name="card_note" value="{{ old('card_note', $values['card_note'] ?? '') }}" class="form-control"></div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('shahbot::admin.welcome_text') }}</label>
                    <textarea name="welcome_text" rows="4" class="form-control">{{ old('welcome_text', $values['welcome_text'] ?? '') }}</textarea>
                    <label class="form-label mt-2">{{ __('shahbot::admin.start_image') }}</label>
                    <input type="file" name="start_image" accept="image/png,image/jpeg" class="form-control">
                    <div class="form-text">{{ __('shahbot::admin.start_image_hint') }}</div>
                    @if ($bot && \Modules\ShahBot\Support\StartImage::path($bot))
                        <label class="form-check mt-1"><input type="checkbox" name="start_image_clear" value="1" class="form-check-input"> {{ __('shahbot::admin.start_image_clear') }}</label>
                    @endif
                    <div class="text-muted small">{{ __('shahbot::admin.welcome_hint') }}</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('shahbot::admin.support_text') }}</label>
                    <textarea name="support_text" rows="4" class="form-control">{{ old('support_text', $values['support_text'] ?? '') }}</textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('shahbot::admin.channels') }}</label>
                    <textarea name="channels" rows="2" class="form-control" dir="ltr">{{ old('channels', $values['channels'] ?? '') }}</textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('shahbot::admin.rules_text') }}</label>
                    <textarea name="rules_text" rows="2" class="form-control">{{ old('rules_text', $values['rules_text'] ?? '') }}</textarea>
                </div>
                <div class="col-12"><button class="btn btn-primary"><i class="bx bx-save"></i> {{ __('shahbot::admin.save') }}</button></div>
            </form>

            @if ($bot && $bot->is_active && $bot->token() !== '')
                <form method="POST" action="{{ route($prefix.'.connect') }}" class="mt-3">
                    @csrf
                    <button class="btn btn-success"><i class="bx bxl-telegram"></i> {{ __('shahbot::admin.connect') }}</button>
                    @if (! empty($webhookInfo['url']))<span class="badge bg-success ms-2">{{ __('shahbot::admin.webhook_ok') }}</span>@endif
                    @if (! empty($webhookInfo['last_error_message']))<div class="text-danger small mt-1">{{ __('shahbot::admin.webhook_error', ['error' => $webhookInfo['last_error_message']]) }}</div>@endif
                    @if (\Modules\ShahBot\Services\WebhookService::webhookUnreachable($webhookInfo))<div class="alert alert-warning small mt-2 mb-0">{{ __('shahbot::admin.webhook_unreachable_hint') }}</div>@endif
                </form>
            @endif
        </div>
    </div>

    {{-- پیام همگانی فقط وقتی معنا دارد که رباتی با توکن سالم وصل باشد. --}}
    @if ($bot && $bot->is_active && $bot->token() !== '')
        <div class="card mt-3" id="my-broadcast">
            <div class="card-body">
                <h6 class="mb-1">{{ __('shahbot::admin.my_broadcast') }}</h6>
                <p class="text-muted small mb-3">{{ __('shahbot::admin.my_broadcast_hint') }}</p>

                <form method="POST" action="{{ route($prefix.'.broadcast') }}" class="row g-3">
                    @csrf
                    <div class="col-md-4">
                        <label class="form-label">{{ __('shahbot::admin.audience') }}</label>
                        <select name="audience" class="form-select">
                            <option value="all">{{ __('shahbot::admin.audience_all') }}</option>
                            <option value="customers">{{ __('shahbot::admin.audience_customers') }}</option>
                            <option value="no_service">{{ __('shahbot::admin.audience_no_service') }}</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ __('shahbot::admin.broadcast_text') }}</label>
                        <textarea name="text" rows="4" maxlength="3500" class="form-control">{{ old('text') }}</textarea>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary"><i class="bx bx-send"></i> {{ __('shahbot::admin.send') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endif
@endsection
