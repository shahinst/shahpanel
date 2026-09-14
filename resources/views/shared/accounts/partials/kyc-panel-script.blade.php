@php
    $kycIdPrefix = $kycIdPrefix ?? 'staff';
    $kycSubmitUrl = $kycSubmitUrl ?? '';
    $kycVerifyUrlTemplate = $kycVerifyUrlTemplate ?? '';
    $kycResetUrlTemplate = $kycResetUrlTemplate ?? '';
    $kycSubmitButtonId = $kycSubmitButtonId ?? null;
@endphp
<script>
(function () {
    const prefix = @json($kycIdPrefix);
    const submitUrl = @json($kycSubmitUrl);
    const verifyUrlTpl = @json($kycVerifyUrlTemplate);
    const resetUrlTpl = @json($kycResetUrlTemplate);
    const createSubmitBtn = @json($kycSubmitButtonId) ? document.getElementById(@json($kycSubmitButtonId)) : null;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || document.querySelector('input[name="_token"]')?.value
        || '';

    const panel = document.getElementById(prefix + '-kyc-panel');
    if (!panel) return;

    const els = {
        verificationId: document.getElementById(prefix + '-kyc-verification-id'),
        firstName: document.getElementById(prefix + '-kyc-first-name'),
        lastName: document.getElementById(prefix + '-kyc-last-name'),
        nationalCode: document.getElementById(prefix + '-kyc-national-code'),
        birthYear: document.getElementById(prefix + '-kyc-birth-year'),
        birthMonth: document.getElementById(prefix + '-kyc-birth-month'),
        birthDay: document.getElementById(prefix + '-kyc-birth-day'),
        birthDate: document.getElementById(prefix + '-kyc-birth-date'),
        mobile: document.getElementById(prefix + '-kyc-mobile'),
        document: document.getElementById(prefix + '-kyc-document'),
        submitBtn: document.getElementById(prefix + '-kyc-submit-btn'),
        verifyBtn: document.getElementById(prefix + '-kyc-verify-btn'),
        resetBtn: document.getElementById(prefix + '-kyc-reset-btn'),
        attempts: document.getElementById(prefix + '-kyc-attempts'),
        error: document.getElementById(prefix + '-kyc-error'),
        success: document.getElementById(prefix + '-kyc-success'),
        badge: document.getElementById(prefix + '-kyc-status-badge'),
        packageSelect: document.getElementById(prefix === 'admin' ? 'admin-package-id' : 'staff-package-id'),
        ownerSelect: document.getElementById(prefix === 'admin' ? 'admin-owner-id' : 'staff-owner-id'),
    };

    let currentVerification = null;
    let kycRequired = false;
    let optionsByIdRef = null;

    const dayPlaceholder = @json(__('kyc.birth_day'));

    function jalaliIsLeapYear(year) {
        const mod = (((year - 474) % 2820) + 2820) % 2820;
        return ((mod + 474 + 38) * 682) % 2816 < 682;
    }

    function jalaliDaysInMonth(year, month) {
        if (month >= 1 && month <= 6) return 31;
        if (month >= 7 && month <= 11) return 30;
        if (month === 12) return jalaliIsLeapYear(year) ? 30 : 29;
        return 31;
    }

    function composeBirthDate() {
        const year = els.birthYear ? els.birthYear.value : '';
        const month = els.birthMonth ? els.birthMonth.value : '';
        const day = els.birthDay ? els.birthDay.value : '';
        const value = (year && month && day)
            ? year + '/' + String(month).padStart(2, '0') + '/' + String(day).padStart(2, '0')
            : '';
        if (els.birthDate) els.birthDate.value = value;
        return value;
    }

    function refreshDayOptions() {
        if (!els.birthDay) return;
        const year = parseInt((els.birthYear && els.birthYear.value) || '0', 10) || 0;
        const month = parseInt((els.birthMonth && els.birthMonth.value) || '0', 10) || 0;
        const maxDay = jalaliDaysInMonth(year, month);
        const previous = els.birthDay.value;

        els.birthDay.innerHTML = '';
        const blank = document.createElement('option');
        blank.value = '';
        blank.textContent = dayPlaceholder;
        els.birthDay.appendChild(blank);
        for (let day = 1; day <= maxDay; day++) {
            const option = document.createElement('option');
            option.value = String(day);
            option.textContent = String(day);
            els.birthDay.appendChild(option);
        }
        if (previous && parseInt(previous, 10) <= maxDay) {
            els.birthDay.value = previous;
        }

        composeBirthDate();
    }

    function normalizeMobileDigits(value) {
        return String(value || '')
            .replace(/[۰-۹]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); })
            .replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
            .replace(/\D+/g, '');
    }

    [els.birthYear, els.birthMonth].forEach(function (el) {
        if (el) el.addEventListener('change', refreshDayOptions);
    });
    if (els.birthDay) els.birthDay.addEventListener('change', composeBirthDate);
    refreshDayOptions();

    function setError(msg) {
        if (!els.error) return;
        if (!msg) { els.error.hidden = true; els.error.textContent = ''; return; }
        els.error.hidden = false;
        els.error.textContent = msg;
    }

    function setSuccess(msg) {
        if (!els.success) return;
        if (!msg) { els.success.hidden = true; els.success.textContent = ''; return; }
        els.success.hidden = false;
        els.success.textContent = msg;
    }

    function updateCreateSubmitGate() {
        if (!createSubmitBtn) return;
        if (!kycRequired) {
            createSubmitBtn.disabled = false;
            createSubmitBtn.removeAttribute('title');
            return;
        }
        const ready = currentVerification && currentVerification.status === 'verified' && els.verificationId?.value;
        createSubmitBtn.disabled = !ready;
        createSubmitBtn.title = ready ? '' : @json(__('kyc.create_blocked_until_verified'));
    }

    function applyVerification(v) {
        currentVerification = v || null;
        if (els.verificationId) els.verificationId.value = v ? String(v.id) : '';
        if (els.badge) els.badge.textContent = v ? (v.status_label || v.status) : '—';

        if (els.attempts && v) {
            els.attempts.textContent = @json(__('kyc.attempts_left'))
                .replace(':count', String(v.remaining_attempts ?? 0))
                .replace(':max', String(v.max_verify_attempts ?? 2));
        } else if (els.attempts) {
            els.attempts.textContent = '';
        }

        if (els.verifyBtn) els.verifyBtn.disabled = !(v && v.can_verify);
        if (els.resetBtn) {
            const showReset = !!(v && v.can_request_reset);
            els.resetBtn.hidden = !showReset;
            if (showReset && (!v.remaining_attempts || v.remaining_attempts <= 0)) {
                els.attempts.textContent = @json(__('kyc.locked_hint'));
            }
        }

        if (v && v.status === 'verified') {
            setSuccess(@json(__('kyc.verified_ready')));
            setError('');
        }

        updateCreateSubmitGate();
    }

    function resetPanelState(keepFields) {
        currentVerification = null;
        if (els.verificationId) els.verificationId.value = '';
        if (!keepFields) {
            [els.firstName, els.lastName, els.nationalCode, els.mobile].forEach(function (el) {
                if (el) el.value = '';
            });
            [els.birthYear, els.birthMonth, els.birthDay].forEach(function (el) {
                if (el) el.value = '';
            });
            refreshDayOptions();
            if (els.document) els.document.value = '';
        }
        setError('');
        setSuccess('');
        applyVerification(null);
    }

    function syncWithPackage(pkg) {
        kycRequired = !!(pkg && pkg.kyc_required);
        panel.hidden = !kycRequired;
        if (!kycRequired) {
            resetPanelState(true);
            updateCreateSubmitGate();
            return;
        }
        updateCreateSubmitGate();
    }

    window['__kycPanel_' + prefix] = {
        syncPackage: function (pkg, optionsMap) {
            if (optionsMap) optionsByIdRef = optionsMap;
            syncWithPackage(pkg);
        },
        isReady: function () {
            return !kycRequired || (currentVerification && currentVerification.status === 'verified' && !!els.verificationId?.value);
        },
        requiresKyc: function () { return kycRequired; },
    };

    function buildUrl(template, id) {
        return String(template).replace('__ID__', String(id)).replace('%7B%7BID%7D%7D', String(id));
    }

    if (els.submitBtn) {
        els.submitBtn.addEventListener('click', function () {
            setError('');
            setSuccess('');
            const pkgId = els.packageSelect ? els.packageSelect.value : '';
            if (!pkgId) {
                setError(@json(__('accounts.package')) + ' الزامی است.');
                return;
            }
            if (!els.document?.files?.length) {
                setError(@json(__('kyc.document')) + ' الزامی است.');
                return;
            }

            const birthDate = composeBirthDate();
            if (!birthDate) {
                setError(@json(__('kyc.birth_date_invalid')));
                return;
            }

            const mobile = normalizeMobileDigits(els.mobile?.value);
            if (!/^(?:0098|98|0)?9\d{9}$/.test(mobile)) {
                setError(@json(__('kyc.mobile_invalid')));
                return;
            }

            const fd = new FormData();
            fd.append('first_name', els.firstName?.value || '');
            fd.append('last_name', els.lastName?.value || '');
            fd.append('national_code', els.nationalCode?.value || '');
            fd.append('birth_date', birthDate);
            fd.append('mobile', mobile);
            fd.append('document', els.document.files[0]);
            fd.append('package_id', pkgId);
            if (els.ownerSelect && els.ownerSelect.value) {
                fd.append('owner_seller_id', els.ownerSelect.value);
            }
            fd.append('_token', csrf);

            els.submitBtn.disabled = true;
            fetch(submitUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: fd,
            })
                .then(function (r) { return r.json().then(function (p) { return { ok: r.ok, payload: p }; }); })
                .then(function (result) {
                    els.submitBtn.disabled = false;
                    if (!result.ok) {
                        const msg = result.payload.message
                            || (result.payload.errors ? Object.values(result.payload.errors).flat().join(' ') : null)
                            || 'خطا در ثبت احراز';
                        setError(msg);
                        return;
                    }
                    applyVerification(result.payload.verification);
                    setSuccess(result.payload.message || @json(__('app.saved')));
                })
                .catch(function () {
                    els.submitBtn.disabled = false;
                    setError('خطا در ارتباط با سرور');
                });
        });
    }

    if (els.verifyBtn) {
        els.verifyBtn.addEventListener('click', function () {
            if (!currentVerification?.id) return;
            setError('');
            setSuccess('');
            els.verifyBtn.disabled = true;
            fetch(buildUrl(verifyUrlTpl, currentVerification.id), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ _token: csrf }),
            })
                .then(function (r) { return r.json().then(function (p) { return { ok: r.ok, payload: p }; }); })
                .then(function (result) {
                    if (result.payload.verification) applyVerification(result.payload.verification);
                    if (!result.ok) {
                        setError(result.payload.message || 'احراز ناموفق بود');
                        return;
                    }
                    setSuccess(result.payload.message || @json(__('kyc.verified_ready')));
                })
                .catch(function () {
                    els.verifyBtn.disabled = false;
                    setError('خطا در ارتباط با سرور');
                });
        });
    }

    if (els.resetBtn) {
        els.resetBtn.addEventListener('click', function () {
            if (!currentVerification?.id) return;
            els.resetBtn.disabled = true;
            fetch(buildUrl(resetUrlTpl, currentVerification.id), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ _token: csrf }),
            })
                .then(function (r) { return r.json().then(function (p) { return { ok: r.ok, payload: p }; }); })
                .then(function (result) {
                    els.resetBtn.disabled = false;
                    if (result.payload.verification) applyVerification(result.payload.verification);
                    if (!result.ok) {
                        setError(result.payload.message || 'خطا');
                        return;
                    }
                    setSuccess(result.payload.message || @json(__('kyc.reset_requested')));
                })
                .catch(function () {
                    els.resetBtn.disabled = false;
                    setError('خطا در ارتباط با سرور');
                });
        });
    }

    // Block form submit until KYC verified when required.
    const form = panel.closest('form');
    if (form) {
        form.addEventListener('submit', function (event) {
            const api = window['__kycPanel_' + prefix];
            if (api && !api.isReady()) {
                event.preventDefault();
                setError(@json(__('kyc.create_blocked_until_verified')));
                panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    }
})();
</script>
