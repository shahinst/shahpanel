@php
    $kycIdPrefix = $kycIdPrefix ?? 'staff';
    $kycSubmitUrl = $kycSubmitUrl ?? null;
    $kycVerifyUrlTemplate = $kycVerifyUrlTemplate ?? null;
    $kycResetUrlTemplate = $kycResetUrlTemplate ?? null;
    $kycJalaliYear = (int) western_digits(jalali_now('Y'));
    $kycJalaliMonths = [
        'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
    ];
@endphp

<div id="{{ $kycIdPrefix }}-kyc-panel" class="border rounded p-3 mb-3 bg-light" hidden>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
        <div>
            <h6 class="mb-1"><i class="bx bx-id-card"></i> {{ __('kyc.section_fields') }}</h6>
            <p class="text-muted small mb-0">{{ __('kyc.package_required_hint') }}</p>
        </div>
        <span class="badge bg-secondary" id="{{ $kycIdPrefix }}-kyc-status-badge">—</span>
    </div>

    <input type="hidden" name="kyc_verification_id" id="{{ $kycIdPrefix }}-kyc-verification-id" value="{{ old('kyc_verification_id') }}">

    <div class="row g-2">
        <div class="col-md-6">
            <label class="form-label" for="{{ $kycIdPrefix }}-kyc-first-name">{{ __('kyc.first_name') }}</label>
            <input type="text" id="{{ $kycIdPrefix }}-kyc-first-name" class="form-control" maxlength="100" autocomplete="off">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="{{ $kycIdPrefix }}-kyc-last-name">{{ __('kyc.last_name') }}</label>
            <input type="text" id="{{ $kycIdPrefix }}-kyc-last-name" class="form-control" maxlength="100" autocomplete="off">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="{{ $kycIdPrefix }}-kyc-national-code">{{ __('kyc.national_code') }}</label>
            <input type="text" id="{{ $kycIdPrefix }}-kyc-national-code" class="form-control" dir="ltr" maxlength="12" inputmode="numeric" autocomplete="off">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="{{ $kycIdPrefix }}-kyc-birth-year">{{ __('kyc.birth_date') }}</label>
            <div class="row g-1">
                <div class="col-4">
                    <select id="{{ $kycIdPrefix }}-kyc-birth-year" class="form-select" dir="ltr" aria-label="{{ __('kyc.birth_year') }}">
                        <option value="">{{ __('kyc.birth_year') }}</option>
                        @for ($kycYear = $kycJalaliYear; $kycYear >= 1300; $kycYear--)
                            <option value="{{ $kycYear }}">{{ $kycYear }}</option>
                        @endfor
                    </select>
                </div>
                <div class="col-4">
                    <select id="{{ $kycIdPrefix }}-kyc-birth-month" class="form-select" aria-label="{{ __('kyc.birth_month') }}">
                        <option value="">{{ __('kyc.birth_month') }}</option>
                        @foreach ($kycJalaliMonths as $kycMonthIndex => $kycMonthName)
                            <option value="{{ $kycMonthIndex + 1 }}">{{ $kycMonthName }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-4">
                    <select id="{{ $kycIdPrefix }}-kyc-birth-day" class="form-select" dir="ltr" aria-label="{{ __('kyc.birth_day') }}">
                        <option value="">{{ __('kyc.birth_day') }}</option>
                    </select>
                </div>
            </div>
            <input type="hidden" name="birth_date" id="{{ $kycIdPrefix }}-kyc-birth-date" value="">
            <p class="form-text text-muted mb-0">{{ __('kyc.birth_date_hint') }}</p>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="{{ $kycIdPrefix }}-kyc-mobile">{{ __('kyc.mobile') }}</label>
            <input type="text" id="{{ $kycIdPrefix }}-kyc-mobile" class="form-control" dir="ltr" placeholder="09121234567" maxlength="15" inputmode="numeric" autocomplete="off">
            <p class="form-text text-muted mb-0">{{ __('kyc.mobile_hint') }}</p>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="{{ $kycIdPrefix }}-kyc-document">{{ __('kyc.document') }}</label>
            <input type="file" id="{{ $kycIdPrefix }}-kyc-document" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*,application/pdf">
            <p class="form-text text-muted mb-0">{{ __('kyc.document_hint') }}</p>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-3">
        <button type="button" class="btn btn-outline-primary btn-sm" id="{{ $kycIdPrefix }}-kyc-submit-btn">
            <i class="bx bx-upload"></i> {{ __('kyc.submit_draft') }}
        </button>
        <button type="button" class="btn btn-primary btn-sm" id="{{ $kycIdPrefix }}-kyc-verify-btn" disabled>
            <i class="bx bx-check-shield"></i> {{ __('kyc.verify') }}
        </button>
        <button type="button" class="btn btn-warning btn-sm" id="{{ $kycIdPrefix }}-kyc-reset-btn" hidden>
            <i class="bx bx-reset"></i> {{ __('kyc.request_reset') }}
        </button>
    </div>

    <p class="small text-muted mt-2 mb-0" id="{{ $kycIdPrefix }}-kyc-attempts"></p>
    <div class="alert alert-danger py-2 mt-2 mb-0" id="{{ $kycIdPrefix }}-kyc-error" hidden></div>
    <div class="alert alert-success py-2 mt-2 mb-0" id="{{ $kycIdPrefix }}-kyc-success" hidden></div>
</div>
