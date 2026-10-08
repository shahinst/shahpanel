@extends('layouts.panel')

@section('page_title', __('shahbot::admin.tab_settings'))

@php
    $tabs = ['connection' => 'set_connection', 'store' => 'set_store', 'wallet' => 'set_wallet', 'online' => 'set_online', 'agents' => 'set_agents', 'fun' => 'set_fun', 'languages' => 'set_languages', 'marketing' => 'set_marketing', 'gates' => 'set_gates', 'texts' => 'set_texts'];
    $tab = array_key_exists($tab, $tabs) ? $tab : 'connection';
    $toggle = function (string $key) use ($settings): string {
        return '<input type="hidden" name="_bool_'.$key.'" value="1">'
            .'<label class="form-check form-switch mb-2"><input type="checkbox" class="form-check-input" name="'.$key.'" value="1" '.($settings->bool($key) ? 'checked' : '').'> '
            .e(__('shahbot::admin.'.$key)).'</label>';
    };
@endphp

@section('panel_content')
@include('shahbot::_nav')

<div class="sb-filters">
    @foreach ($tabs as $key => $label)
        <a href="{{ route('admin.shahbot.settings', ['tab' => $key]) }}" @class(['is-active' => $tab === $key])>{{ __('shahbot::admin.'.$label) }}</a>
    @endforeach
</div>

<div class="sb-box">
    <div class="sb-body">
        <form method="POST" action="{{ route('admin.shahbot.settings.update') }}">
            @csrf
            <input type="hidden" name="tab" value="{{ $tab }}">

            @if ($tab === 'connection')
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ __('shahbot::admin.bot_token') }}</label>
                        <input type="password" name="bot_token" class="form-control" dir="ltr" autocomplete="off" placeholder="{{ $settings->get('bot_token') !== '' ? '••••••••••' : '123456:ABC...' }}">
                        @if ($settings->get('bot_token') !== '')<div class="sb-muted mt-1">{{ __('shahbot::admin.bot_token_saved') }} @if ($settings->get('bot_username'))({{ '@'.$settings->get('bot_username') }})@endif</div>@endif
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('shahbot::admin.owner') }}</label>
                        <select name="owner_user_id" class="form-select">
                            <option value="">{{ __('shahbot::admin.choose') }}</option>
                            @foreach ($owners as $owner)
                                <option value="{{ $owner->id }}" @selected((int) $settings->get('owner_user_id') === $owner->id)>{{ $owner->full_name ?: $owner->username }} ({{ $owner->username }} · {{ $owner->role->label() }})</option>
                            @endforeach
                        </select>
                        <div class="sb-muted mt-1">{{ __('shahbot::admin.owner_hint') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('shahbot::admin.mode') }}</label>
                        <select name="mode" class="form-select">
                            <option value="webhook" @selected($settings->get('mode') === 'webhook')>{{ __('shahbot::admin.mode_webhook') }}</option>
                            <option value="polling" @selected($settings->get('mode') === 'polling')>{{ __('shahbot::admin.mode_polling') }}</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('shahbot::admin.proxy') }}</label>
                        <input type="text" name="proxy" value="{{ $settings->get('proxy') }}" class="form-control" dir="ltr">
                        <div class="sb-muted mt-1">{{ __('shahbot::admin.proxy_hint') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('shahbot::admin.admin_chat_ids') }}</label>
                        <textarea name="admin_chat_ids" rows="3" class="form-control" dir="ltr">{{ $settings->get('admin_chat_ids') }}</textarea>
                        <div class="sb-muted mt-1">{{ __('shahbot::admin.admin_chat_ids_hint') }}</div>
                        <label class="form-label mt-2">{{ __('shahbot::admin.support_chat_ids') }}</label>
                        <textarea name="support_chat_ids" rows="2" class="form-control" dir="ltr">{{ $settings->get('support_chat_ids') }}</textarea>
                        <div class="sb-muted mt-1">{{ __('shahbot::admin.support_chat_ids_hint') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('shahbot::admin.webhook_url') }}</label>
                        <input type="text" readonly value="{{ $webhookUrl }}" class="form-control" dir="ltr">
                        <div class="mt-2">
                            {{ __('shahbot::admin.webhook_status') }}:
                            @if (! empty($webhookInfo['url']))
                                <span class="sb-pill ok">{{ __('shahbot::admin.webhook_ok') }}</span>
                            @else
                                <span class="sb-pill">{{ __('shahbot::admin.webhook_none') }}</span>
                            @endif
                            @if (! empty($webhookInfo['pending_update_count']))<span class="sb-muted">{{ __('shahbot::admin.webhook_pending', ['count' => persian_digits($webhookInfo['pending_update_count'])]) }}</span>@endif
                            @if (! empty($webhookInfo['last_error_message']))<div class="text-danger small">{{ __('shahbot::admin.webhook_error', ['error' => $webhookInfo['last_error_message']]) }}</div>@endif
                    @if (\Modules\ShahBot\Services\WebhookService::webhookUnreachable($webhookInfo))<div class="alert alert-warning small mt-2 mb-0">{{ __('shahbot::admin.webhook_unreachable_hint') }}</div>@endif
                        </div>
                    </div>
                </div>
                <p class="sb-muted mt-3 mb-0">{{ __('shahbot::admin.cron_hint') }}</p>
            @elseif ($tab === 'store')
                {!! $toggle('sales_enabled') !!}
                <div class="mb-3">
                    <label class="form-label">{{ __('shahbot::admin.closed_text') }}</label>
                    <textarea name="closed_text" rows="2" class="form-control">{{ $settings->get('closed_text') }}</textarea>
                </div>
                {!! $toggle('renew_enabled') !!}
                <div class="row g-2 mb-2">
                    <div class="col-md-4"><label class="form-label">{{ __('shahbot::admin.extra_gb_price') }}</label><input type="number" min="0" step="any" name="extra_gb_price" value="{{ $settings->get('extra_gb_price') }}" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">{{ __('shahbot::admin.extra_gb_options') }}</label><input type="text" name="extra_gb_options" value="{{ $settings->get('extra_gb_options') }}" class="form-control" dir="ltr"></div>
                </div>
                {!! $toggle('show_portal_link') !!}
                {!! $toggle('mini_app_enabled') !!}
                <p class="sb-muted">{{ __('shahbot::admin.mini_app_hint') }}</p>
                <hr>
                {!! $toggle('transfer_enabled') !!}
                {!! $toggle('location_enabled') !!}
                <div class="mb-2" style="max-width:260px"><label class="form-label">{{ __('shahbot::admin.location_fee') }}</label><input type="number" min="0" step="any" name="location_fee" value="{{ $settings->get('location_fee') }}" class="form-control"></div>
                {!! $toggle('refund_enabled') !!}
                <p class="sb-muted">{{ __('shahbot::admin.ops_hint') }}</p>
                <hr>
                {!! $toggle('test_enabled') !!}
                {!! $toggle('test_requires_phone') !!}
                <div class="mb-2" style="max-width:520px">
                    <label class="form-label">{{ __('shahbot::admin.test_duration') }}</label>
                    <select name="test_duration_id" class="form-select">
                        <option value="">{{ __('shahbot::admin.choose') }}</option>
                        @foreach ($testDurations as $duration)
                            <option value="{{ $duration->id }}" @selected((int) $settings->get('test_duration_id') === $duration->id)>{{ $duration->package->name }} · {{ $duration->tier->label() }}</option>
                        @endforeach
                    </select>
                    <div class="sb-muted mt-1">{{ __('shahbot::admin.test_duration_hint') }}</div>
                </div>
            @elseif ($tab === 'wallet')
                {!! $toggle('topup_enabled') !!}
                <p class="sb-muted">{{ __('shahbot::admin.topup_hint') }}</p>
                <div class="row g-3">
                    <div class="col-md-3"><label class="form-label">{{ __('shahbot::admin.topup_min') }}</label><input type="number" min="0" step="any" name="topup_min" value="{{ $settings->get('topup_min') }}" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">{{ __('shahbot::admin.topup_max') }}</label><input type="number" min="0" step="any" name="topup_max" value="{{ $settings->get('topup_max') }}" class="form-control"></div>
                    <div class="col-md-6"></div>
                    <div class="col-md-4"><label class="form-label">{{ __('shahbot::admin.card_number') }}</label><input type="text" name="card_number" value="{{ $settings->get('card_number') }}" class="form-control" dir="ltr"></div>
                    <div class="col-md-4"><label class="form-label">{{ __('shahbot::admin.card_holder') }}</label><input type="text" name="card_holder" value="{{ $settings->get('card_holder') }}" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">{{ __('shahbot::admin.card_bank') }}</label><input type="text" name="card_bank" value="{{ $settings->get('card_bank') }}" class="form-control"></div>
                    <div class="col-12"><label class="form-label">{{ __('shahbot::admin.card_note') }}</label><textarea name="card_note" rows="2" class="form-control">{{ $settings->get('card_note') }}</textarea></div>
                </div>
            @elseif ($tab === 'online')
                <p class="sb-muted">{{ __('shahbot::admin.online_hint') }}</p>
                <div class="mb-3">
                    <b>{{ __('shahbot::admin.method_status_note') }}:</b>
                    @foreach (['zp' => 'pay_zarinpal', 'cr' => 'pay_crypto', 'st' => 'pay_stars', 'card' => 'method_card'] as $code => $label)
                        <span @class(['sb-pill', 'ok' => in_array($code, $methods, true)])>{{ __('shahbot::admin.'.$label) }}: {{ in_array($code, $methods, true) ? __('shahbot::admin.method_on') : __('shahbot::admin.method_off') }}</span>
                    @endforeach
                </div>
                {!! $toggle('pay_zarinpal') !!}
                {!! $toggle('pay_crypto') !!}
                <hr>
                {!! $toggle('pay_stars') !!}
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">{{ __('shahbot::admin.stars_rate') }}</label><input type="number" min="0" step="any" name="stars_rate" value="{{ $settings->get('stars_rate') }}" class="form-control"></div>
                </div>
                <p class="sb-muted mt-2">{{ __('shahbot::admin.stars_hint') }}</p>
            @elseif ($tab === 'agents')
                {!! $toggle('agency_enabled') !!}
                <p class="sb-muted">{{ __('shahbot::admin.agency_hint') }}</p>
                <div class="row g-3 mb-3">
                    <div class="col-md-8"><label class="form-label">{{ __('shahbot::admin.agency_text') }}</label><textarea name="agency_text" rows="2" class="form-control">{{ $settings->get('agency_text') }}</textarea></div>
                    <div class="col-md-2"><label class="form-label">{{ __('shahbot::admin.agency_discount') }}</label><input type="number" min="0" max="100" step="any" name="agency_discount" value="{{ $settings->get('agency_discount') }}" class="form-control"></div>
                    <div class="col-md-2"><label class="form-label">{{ __('shahbot::admin.bulk_max') }}</label><input type="number" min="1" max="100" name="bulk_max" value="{{ $settings->get('bulk_max') }}" class="form-control"></div>
                </div>
                <hr>
                <p class="sb-muted">{{ __('shahbot::admin.bot_access_moved') }} <a href="{{ route('admin.shahbot.access') }}">{{ __('shahbot::admin.bot_access') }}</a></p>
            @elseif ($tab === 'fun')
                {!! $toggle('wheel_enabled') !!}
                {!! $toggle('wheel_buyers_only') !!}
                <div class="row g-3">
                    <div class="col-md-3"><label class="form-label">{{ __('shahbot::admin.wheel_cooldown_hours') }}</label><input type="number" min="1" max="720" name="wheel_cooldown_hours" value="{{ $settings->get('wheel_cooldown_hours') }}" class="form-control"></div>
                    <div class="col-12">
                        <label class="form-label">{{ __('shahbot::admin.wheel_prizes') }}</label>
                        <textarea name="wheel_prizes" rows="7" class="form-control" dir="auto">{{ $settings->get('wheel_prizes') }}</textarea>
                        <div class="sb-muted mt-1">{{ __('shahbot::admin.wheel_prizes_hint') }}</div>
                    </div>
                </div>
            @elseif ($tab === 'languages')
                <input type="hidden" name="_languages" value="1">
                <p class="sb-muted">{{ __('shahbot::admin.languages_hint') }}</p>
                @php $enabledLanguages = app(\Modules\ShahBot\Support\BotLocale::class)->enabled(); @endphp
                <div class="mb-3">
                    @foreach (\Modules\ShahBot\Support\BotLocale::NAMES as $code => $name)
                        <label class="form-check form-check-inline"><input type="checkbox" class="form-check-input" name="languages[]" value="{{ $code }}" @checked(in_array($code, $enabledLanguages, true))> {{ $name }}</label>
                    @endforeach
                </div>
                <div style="max-width:260px">
                    <label class="form-label">{{ __('shahbot::admin.default_language') }}</label>
                    <select name="default_language" class="form-select">
                        @foreach (\Modules\ShahBot\Support\BotLocale::NAMES as $code => $name)
                            <option value="{{ $code }}" @selected($settings->get('default_language') === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            @elseif ($tab === 'marketing')
                {!! $toggle('referral_enabled') !!}
                <div class="row g-3 mb-2">
                    <div class="col-md-3"><label class="form-label">{{ __('shahbot::admin.referral_percent') }}</label><input type="number" min="0" max="100" step="any" name="referral_percent" value="{{ $settings->get('referral_percent') }}" class="form-control"></div>
                </div>
                {!! $toggle('referral_first_only') !!}
                <hr>
                {!! $toggle('reminder_enabled') !!}
                <div class="mb-2"><label class="form-label">{{ __('shahbot::admin.loyalty_tiers') }}</label><textarea name="loyalty_tiers" rows="3" class="form-control" dir="ltr" placeholder="2000000 = 5&#10;5000000 = 10">{{ $settings->get('loyalty_tiers') }}</textarea><div class="sb-muted">{{ __('shahbot::admin.loyalty_tiers_hint') }}</div></div>
                {!! $toggle('outage_comp_enabled') !!}
                <div class="row g-2 mb-2"><div class="col-md-4"><label class="form-label">{{ __('shahbot::admin.outage_comp_minutes') }}</label><input type="number" min="5" max="1440" name="outage_comp_minutes" value="{{ $settings->get('outage_comp_minutes') }}" class="form-control"></div></div>
                {!! $toggle('auto_answer_enabled') !!}
                <div class="row g-2 mb-2"><div class="col-md-4"><label class="form-label">{{ __('shahbot::admin.low_balance_alert') }}</label><input type="number" min="0" step="any" name="low_balance_alert" value="{{ $settings->get('low_balance_alert') }}" class="form-control"></div></div>
                {!! $toggle('winback_enabled') !!}
                <div class="row g-2 mb-2">
                    @foreach (['winback_days' => [1, 30], 'winback_percent' => [1, 90], 'winback_valid_days' => [1, 60]] as $key => [$min, $max])
                        <div class="col-md-3"><label class="form-label">{{ __('shahbot::admin.'.$key) }}</label><input type="number" min="{{ $min }}" max="{{ $max }}" name="{{ $key }}" value="{{ $settings->get($key) }}" class="form-control"></div>
                    @endforeach
                </div>
                <div class="row g-3">
                    <div class="col-md-3"><label class="form-label">{{ __('shahbot::admin.reminder_days') }}</label><input type="number" min="1" max="30" name="reminder_days" value="{{ $settings->get('reminder_days') }}" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">{{ __('shahbot::admin.low_traffic_percent') }}</label><input type="number" min="0" max="90" name="low_traffic_percent" value="{{ $settings->get('low_traffic_percent') }}" class="form-control"></div>
                </div>
            @elseif ($tab === 'gates')
                <div class="mb-3" style="max-width:520px">
                    <label class="form-label">{{ __('shahbot::admin.channels') }}</label>
                    <textarea name="channels" rows="3" class="form-control" dir="ltr">{{ $settings->get('channels') }}</textarea>
                    <div class="sb-muted mt-1">{{ __('shahbot::admin.channels_hint') }}</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('shahbot::admin.rules_text') }}</label>
                    <textarea name="rules_text" rows="6" class="form-control">{{ $settings->get('rules_text') }}</textarea>
                </div>
                {!! $toggle('require_phone') !!}
                {!! $toggle('iran_phone_only') !!}
            @else
                <div class="mb-3">
                    <label class="form-label">{{ __('shahbot::admin.welcome_text') }}</label>
                    <textarea name="welcome_text" rows="5" class="form-control">{{ $settings->get('welcome_text') }}</textarea>
                    <div class="sb-muted mt-1">{{ __('shahbot::admin.welcome_hint') }}</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('shahbot::admin.support_text') }}</label>
                    <textarea name="support_text" rows="3" class="form-control">{{ $settings->get('support_text') }}</textarea>
                </div>
            @endif

            <button class="btn btn-primary mt-3"><i class="bx bx-save"></i> {{ __('shahbot::admin.save') }}</button>
        </form>

        @if ($tab === 'connection')
            <form method="POST" action="{{ route('admin.shahbot.settings.connect') }}" class="mt-2">
                @csrf
                <button class="btn btn-success"><i class="bx bxl-telegram"></i> {{ __('shahbot::admin.connect') }}</button>
            </form>
        @endif
    </div>
</div>
@endsection
