@php use App\Support\SmsSettings; @endphp
@extends('layouts.panel')

@section('page_title', __('sms.title'))

@section('panel_content')
<x-page-header :title="__('sms.title')" :subtitle="__('sms.subtitle')" />

<div class="row">
    <div class="col-lg-10">
        <div class="panel-modern-card mb-4">
            <div class="card-head"><h3>{{ __('sms.provider') }} — {{ __('sms.provider_sms_ir') }}</h3></div>
            <div class="card-body">
                <p class="text-muted small">{{ __('sms.provider_hint') }}</p>

                <form method="POST" action="{{ route('admin.sms.update') }}">
                    @csrf
                    @method('PUT')

                    <input type="hidden" name="sms_provider" value="sms_ir">

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

                    <hr class="my-4">

                    <h4 class="h6 mb-3">{{ __('sms.verify_template_section') }}</h4>
                    <p class="text-muted small">{{ __('sms.verify_template_section_hint') }}</p>

                    <div class="mb-3">
                        <input type="hidden" name="sms_account_login_enabled" value="0">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="sms_account_login_enabled" id="sms_account_login_enabled" value="1"
                                   @checked((bool) old('sms_account_login_enabled', $accountLoginSmsEnabled))>
                            <label class="form-check-label" for="sms_account_login_enabled">{{ __('sms.account_login_enabled') }}</label>
                        </div>
                        <p class="text-muted small mt-1 mb-0">{{ __('sms.account_login_enabled_hint') }}</p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="sms_ir_verify_template_id">{{ __('sms.verify_template_id') }}</label>
                        @if ($templates !== [])
                            <select name="sms_ir_verify_template_id" id="sms_ir_verify_template_id" class="form-select" dir="ltr" required>
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
                            <input type="number" name="sms_ir_verify_template_id" id="sms_ir_verify_template_id" class="form-control" dir="ltr" min="1" required
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

                    @if ($hasApiKey)
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

        @if ($hasApiKey)
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
