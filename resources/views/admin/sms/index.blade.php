@php use App\Support\SmsSettings; @endphp
@extends('layouts.panel')

@section('page_title', __('sms.title'))

@section('panel_content')
<x-page-header :title="__('sms.title')" :subtitle="__('sms.subtitle')" />

<div class="row">
    <div class="col-lg-10">
        <div class="panel-modern-card mb-4">
            <div class="card-head"><h3>{{ __('sms.provider') }}</h3></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.sms.update') }}">
                    @csrf
                    @method('PUT')

                    @php $currentProvider = old('sms_provider', $provider); @endphp
                    <div class="sms-providers mb-4" role="radiogroup" aria-label="{{ __('sms.provider') }}">
                        @foreach ([SmsSettings::PROVIDER_SMS_IR => ['sms.provider_sms_ir', 'bx-message-square-dots'], SmsSettings::PROVIDER_IDEHPAYAM => ['sms.provider_idehpayam', 'bx-bulb']] as $value => [$labelKey, $icon])
                            <label class="sms-provider">
                                <input type="radio" name="sms_provider" value="{{ $value }}" @checked($currentProvider === $value) data-sms-provider>
                                <span><i class="bx {{ $icon }}"></i> {{ __($labelKey) }}</span>
                            </label>
                        @endforeach
                    </div>

                    {{-- IdehPayam --}}
                    <div data-provider-section="{{ SmsSettings::PROVIDER_IDEHPAYAM }}" @if ($currentProvider !== SmsSettings::PROVIDER_IDEHPAYAM) hidden @endif>
                        <p class="text-muted small">{{ __('sms.idehpayam_hint') }}</p>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="idehpayam_username">{{ __('sms.idehpayam_username') }}</label>
                                <input type="text" name="idehpayam_username" id="idehpayam_username" class="form-control" dir="ltr" autocomplete="off"
                                       value="{{ old('idehpayam_username', $idehPayam['username']) }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="idehpayam_password">{{ __('sms.idehpayam_password') }}</label>
                                <input type="password" name="idehpayam_password" id="idehpayam_password" class="form-control" dir="ltr" autocomplete="new-password"
                                       placeholder="{{ $idehPayam['has_password'] ? __('sms.api_key_keep') : '' }}">
                                @if ($idehPayam['has_password'])
                                    <p class="text-success small mt-1 mb-0"><i class="bx bx-check-circle"></i> {{ __('sms.idehpayam_password_saved') }}</p>
                                @endif
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="idehpayam_from">{{ __('sms.idehpayam_from') }}</label>
                                <input type="text" name="idehpayam_from" id="idehpayam_from" class="form-control" dir="ltr" inputmode="numeric"
                                       value="{{ old('idehpayam_from', $idehPayam['from']) }}" placeholder="3000xxxx">
                                @error('idehpayam_from')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                <p class="text-muted small mt-1 mb-0">{{ __('sms.idehpayam_from_hint') }}</p>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="idehpayam_type">{{ __('sms.idehpayam_type') }}</label>
                                <select name="idehpayam_type" id="idehpayam_type" class="form-select">
                                    <option value="0" @selected((int) old('idehpayam_type', $idehPayam['type']) === 0)>{{ __('sms.idehpayam_type_normal') }} (type = 0)</option>
                                    <option value="1" @selected((int) old('idehpayam_type', $idehPayam['type']) === 1)>{{ __('sms.idehpayam_type_flash') }} (type = 1)</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="idehpayam_base_url">{{ __('sms.idehpayam_base_url') }}</label>
                                <input type="url" name="idehpayam_base_url" id="idehpayam_base_url" class="form-control" dir="ltr"
                                       value="{{ old('idehpayam_base_url', $idehPayam['base_url'] !== $idehPayam['default_base_url'] ? $idehPayam['base_url'] : '') }}"
                                       placeholder="{{ $idehPayam['default_base_url'] }}">
                                @error('idehpayam_base_url')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                <p class="text-muted small mt-1 mb-0">{{ __('sms.idehpayam_base_url_hint', ['url' => $idehPayam['default_base_url']]) }}</p>
                            </div>
                        </div>
                        <hr class="my-4">
                    </div>

                    {{-- sms.ir --}}
                    <div data-provider-section="{{ SmsSettings::PROVIDER_SMS_IR }}" @if ($currentProvider !== SmsSettings::PROVIDER_SMS_IR) hidden @endif>
                    <p class="text-muted small">{{ __('sms.provider_hint') }}</p>
                    <div class="mb-3">
                        <label class="form-label" for="sms_ir_api_key">{{ __('sms.api_key') }}</label>
                        <input type="password" name="sms_ir_api_key" id="sms_ir_api_key" class="form-control" dir="ltr"
                               autocomplete="off" placeholder="{{ $hasApiKey ? __('sms.api_key_keep') : __('sms.api_key_placeholder') }}">
                        @if ($hasApiKey)
                            <p class="text-success small mt-1 mb-0"><i class="bx bx-check-circle"></i> {{ __('sms.api_key_saved') }}</p>
                        @endif
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="sms_ir_line_number">{{ __('sms.line_number') }}</label>
                        @if ($lines !== [])
                            <select name="sms_ir_line_number" id="sms_ir_line_number" class="form-select" dir="ltr">
                                <option value="">{{ __('sms.line_select') }}</option>
                                @foreach ($lines as $line)
                                    @php $lineVal = (string) $line; @endphp
                                    <option value="{{ $lineVal }}" @selected((string) $lineNumber === $lineVal)>{{ persian_digits($lineVal) }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="text" name="sms_ir_line_number" id="sms_ir_line_number" class="form-control" dir="ltr"
                                   value="{{ old('sms_ir_line_number', $lineNumber) }}" placeholder="30004505000017">
                        @endif
                        <p class="text-muted small mt-1 mb-0">{{ __('sms.line_number_hint') }}</p>
                    </div>

                    </div>

                    <div data-provider-section="{{ SmsSettings::PROVIDER_SMS_IR }}" @if ($currentProvider !== SmsSettings::PROVIDER_SMS_IR) hidden @endif>
                        <h4 class="h6 mb-3">{{ __('sms.verify_template_section') }}</h4>
                        <p class="text-muted small">{{ __('sms.verify_template_section_hint') }}</p>
                    </div>
                    <div data-provider-section="{{ SmsSettings::PROVIDER_IDEHPAYAM }}" @if ($currentProvider !== SmsSettings::PROVIDER_IDEHPAYAM) hidden @endif>
                        <h4 class="h6 mb-3">{{ __('sms.idehpayam_login_section') }}</h4>
                        <p class="text-muted small">{{ __('sms.idehpayam_login_section_hint') }}</p>
                    </div>

                    <div class="mb-3">
                        <input type="hidden" name="sms_account_login_enabled" value="0">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="sms_account_login_enabled" id="sms_account_login_enabled" value="1"
                                   @checked((bool) old('sms_account_login_enabled', $accountLoginSmsEnabled))>
                            <label class="form-check-label" for="sms_account_login_enabled">{{ __('sms.account_login_enabled') }}</label>
                        </div>
                        <p class="text-muted small mt-1 mb-0">{{ __('sms.account_login_enabled_hint') }}</p>
                    </div>

                    <div class="mb-3" data-provider-section="{{ SmsSettings::PROVIDER_SMS_IR }}" @if ($currentProvider !== SmsSettings::PROVIDER_SMS_IR) hidden @endif>
                        <label class="form-label" for="sms_ir_verify_template_id">{{ __('sms.verify_template_id') }}</label>
                        @if ($templates !== [])
                            <select name="sms_ir_verify_template_id" id="sms_ir_verify_template_id" class="form-select" dir="ltr">
                                <option value="">{{ __('sms.verify_template_select') }}</option>
                                @foreach ($templates as $template)
                                    <option value="{{ $template['id'] }}" @selected((int) old('sms_ir_verify_template_id', $verifyTemplateId) === $template['id'])>
                                        {{ persian_digits((string) $template['id']) }} — {{ $template['title'] }}
                                        @if (! empty($template['text']))
                                            ({{ \Illuminate\Support\Str::limit($template['text'], 60) }})
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <input type="number" name="sms_ir_verify_template_id" id="sms_ir_verify_template_id" class="form-control" dir="ltr" min="1"
                                   value="{{ old('sms_ir_verify_template_id', $verifyTemplateId) }}" placeholder="123456">
                        @endif
                        <p class="text-muted small mt-1 mb-0">{{ __('sms.verify_template_id_hint') }}</p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="sms_verify_login_parameter">{{ __('sms.verify_parameter_name') }}</label>
                        <input type="text" name="sms_verify_login_parameter" id="sms_verify_login_parameter" class="form-control" dir="ltr" required
                               value="{{ old('sms_verify_login_parameter', $verifyParameterName) }}" pattern="[A-Za-z0-9_]+">
                        <p class="text-muted small mt-1 mb-0">{{ __('sms.verify_parameter_name_hint') }}</p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="sms_account_login_message">{{ __('sms.account_message_text') }}</label>
                        <textarea name="sms_account_login_message" id="sms_account_login_message" class="form-control" rows="3" required>{{ old('sms_account_login_message', $accountLoginMessage) }}</textarea>
                        <p class="text-muted small mt-1 mb-0">{{ __('sms.account_message_text_hint') }}</p>
                    </div>

                    @if ($hasApiKey && $provider === SmsSettings::PROVIDER_SMS_IR)
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <span class="text-muted">{{ __('sms.credit') }}:</span>
                                <strong>{{ $credit !== null ? persian_digits(number_format($credit, 2)) : __('sms.credit_unknown') }}</strong>
                            </div>
                            @if (SmsSettings::isAccountLoginSmsReady())
                                <div class="col-md-6">
                                    <span class="text-success small"><i class="bx bx-check-circle"></i> {{ __('sms.account_sms_ready') }}</span>
                                </div>
                            @endif
                        </div>
                        <p class="text-muted small mb-3">{{ __('sms.refresh_on_save') }}</p>
                    @endif

                    @if ($panelError)
                        <x-alert type="warning" class="mb-3">{{ __('sms.panel_error') }}: {{ $panelError }}</x-alert>
                    @endif

                    <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
                </form>
            </div>
        </div>

        @if ($providerConfigured && $provider === SmsSettings::PROVIDER_IDEHPAYAM)
            <div class="panel-modern-card">
                <div class="card-head"><h3>{{ __('sms.test_section') }} — {{ __('sms.provider_idehpayam') }}</h3></div>
                <div class="card-body">
                    <p class="text-muted small">{{ __('sms.idehpayam_test_hint') }}</p>
                    <form method="POST" action="{{ route('admin.sms.test') }}">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="test_mobile">{{ __('sms.idehpayam_test_mobiles') }}</label>
                                <textarea name="test_mobile" id="test_mobile" class="form-control" dir="ltr" rows="3" required placeholder="09120000000">{{ old('test_mobile') }}</textarea>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="test_message">{{ __('sms.test_message') }}</label>
                                <textarea name="test_message" id="test_message" class="form-control" rows="3"
                                          placeholder="{{ __('sms.idehpayam_test_message_placeholder') }}">{{ old('test_message') }}</textarea>
                            </div>
                        </div>
                        <x-button type="submit" variant="secondary" class="mt-3"><i class="bx bx-send"></i> {{ __('sms.test_send') }}</x-button>
                    </form>
                </div>
            </div>
        @elseif ($hasApiKey && $provider === SmsSettings::PROVIDER_SMS_IR)
            <div class="panel-modern-card">
                <div class="card-head"><h3>{{ __('sms.test_section') }}</h3></div>
                <div class="card-body">
                    <p class="text-muted small">
                        @if (SmsSettings::isAccountLoginSmsReady())
                            {{ __('sms.test_section_verify_hint') }}
                        @else
                            {{ __('sms.test_section_hint') }}
                        @endif
                    </p>

                    <form method="POST" action="{{ route('admin.sms.test') }}">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="test_mobile">{{ __('sms.test_mobile') }}</label>
                                <input type="text" name="test_mobile" id="test_mobile" class="form-control" dir="ltr" required
                                       value="{{ old('test_mobile') }}" placeholder="{{ __('sms.test_mobile_placeholder') }}">
                            </div>
                            @if (SmsSettings::isAccountLoginSmsReady())
                                <div class="col-md-8">
                                    <label class="form-label" for="test_login_url">{{ __('sms.test_login_url') }}</label>
                                    <input type="url" name="test_login_url" id="test_login_url" class="form-control" dir="ltr"
                                           value="{{ old('test_login_url', url('/portal/example')) }}" placeholder="https://example.com/portal/...">
                                </div>
                            @else
                                <div class="col-md-8">
                                    <label class="form-label" for="test_message">{{ __('sms.test_message') }}</label>
                                    <textarea name="test_message" id="test_message" class="form-control" rows="2" required
                                              placeholder="{{ __('sms.test_message_placeholder') }}">{{ old('test_message', __('ui.sms_test_message_default')) }}</textarea>
                                </div>
                            @endif
                        </div>
                        <x-button type="submit" variant="secondary" class="mt-3">
                            <i class="bx bx-send"></i> {{ __('sms.test_send') }}
                        </x-button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

@push('styles')
<style>
    .sms-providers { display: flex; flex-wrap: wrap; gap: 10px; }
    .sms-provider { cursor: pointer; margin: 0; }
    .sms-provider input { position: absolute; opacity: 0; pointer-events: none; }
    .sms-provider span { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border: 1.5px solid var(--border, #e6e9ef); border-radius: 12px; font-weight: 600; background: var(--surface, #fff); transition: border-color .15s, background .15s; }
    .sms-provider input:checked + span { border-color: var(--accent, #4f46e5); background: var(--accent-soft, #eef2ff); color: var(--accent, #4f46e5); }
    .sms-provider input:focus-visible + span { outline: 2px solid var(--accent, #4f46e5); outline-offset: 2px; }
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('change', function (event) {
    if (!event.target.matches('[data-sms-provider]')) return;
    document.querySelectorAll('[data-provider-section]').forEach(function (section) {
        section.hidden = section.getAttribute('data-provider-section') !== event.target.value;
    });
});
</script>
@endpush
