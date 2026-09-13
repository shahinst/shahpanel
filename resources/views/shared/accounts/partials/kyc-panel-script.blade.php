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
        birthDate: document.getElementById(prefix + '-kyc-birth-date'),
        cardNumber: document.getElementById(prefix + '-kyc-card-number'),
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
            [els.firstName, els.lastName, els.nationalCode, els.birthDate, els.cardNumber].forEach(function (el) {
                if (el) el.value = '';
            });
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

            const fd = new FormData();
            fd.append('first_name', els.firstName?.value || '');
            fd.append('last_name', els.lastName?.value || '');
            fd.append('national_code', els.nationalCode?.value || '');
            fd.append('birth_date', els.birthDate?.value || '');
            fd.append('card_number', els.cardNumber?.value || '');
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
