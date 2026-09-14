@php
    $kycIdPrefix = $kycIdPrefix ?? 'staff';
    $kycSubmitUrl = $kycSubmitUrl ?? '';
    $kycVerifyUrlTemplate = $kycVerifyUrlTemplate ?? '';
    $kycResetUrlTemplate = $kycResetUrlTemplate ?? '';
    $kycSubmitButtonId = $kycSubmitButtonId ?? null;
@endphp
@once
<script>
(function () {
    function pad2(n) {
        return String(n).padStart(2, '0');
    }

    function currentJalaliYear() {
        const now = new Date();
        const gy = now.getFullYear();
        const gm = now.getMonth() + 1;
        const gd = now.getDate();
        return (gm > 3 || (gm === 3 && gd >= 21)) ? (gy - 621) : (gy - 622);
    }

    function daysInJalaliMonth(year, month) {
        if (month >= 1 && month <= 6) return 31;
        if (month >= 7 && month <= 11) return 30;
        const leaps = [1, 5, 9, 13, 17, 22, 26, 30];
        return leaps.includes(Number(year) % 33) ? 30 : 29;
    }

    window.__initJalaliDatepickers = function (root) {
        const scope = root || document;
        scope.querySelectorAll('[data-kyc-jalali-date]').forEach(function (wrap) {
            if (wrap.dataset.ready === '1') return;
            wrap.dataset.ready = '1';

            const pfx = wrap.getAttribute('data-kyc-jalali-date');
            const yearEl = document.getElementById(pfx + '-kyc-birth-year');
            const monthEl = document.getElementById(pfx + '-kyc-birth-month');
            const dayEl = document.getElementById(pfx + '-kyc-birth-day');
            const hiddenEl = document.getElementById(pfx + '-kyc-birth-date');
            if (!yearEl || !monthEl || !dayEl || !hiddenEl) return;

            const maxYear = currentJalaliYear();
            for (let y = maxYear; y >= 1300; y--) {
                const opt = document.createElement('option');
                opt.value = String(y);
                opt.textContent = String(y);
                yearEl.appendChild(opt);
            }

            function rebuildDays() {
                const year = Number(yearEl.value);
                const month = Number(monthEl.value);
                const prev = dayEl.value;
                dayEl.innerHTML = '<option value="">روز</option>';
                if (!year || !month) {
                    syncHidden();
                    return;
                }
                const maxDay = daysInJalaliMonth(year, month);
                for (let d = 1; d <= maxDay; d++) {
                    const opt = document.createElement('option');
                    opt.value = String(d);
                    opt.textContent = pad2(d);
                    dayEl.appendChild(opt);
                }
                if (prev && Number(prev) <= maxDay) dayEl.value = prev;
                syncHidden();
            }

            function syncHidden() {
                const y = yearEl.value;
                const m = monthEl.value;
                const d = dayEl.value;
                hiddenEl.value = (y && m && d) ? (y + '/' + pad2(m) + '/' + pad2(d)) : '';
            }

            yearEl.addEventListener('change', rebuildDays);
            monthEl.addEventListener('change', rebuildDays);
            dayEl.addEventListener('change', syncHidden);

            wrap.__resetJalaliDate = function () {
                yearEl.value = '';
                monthEl.value = '';
                dayEl.innerHTML = '<option value="">روز</option>';
                hiddenEl.value = '';
            };
        });
    };

    document.addEventListener('DOMContentLoaded', function () {
        window.__initJalaliDatepickers(document);
    });
    window.__initJalaliDatepickers(document);
})();
</script>
@endonce
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
        birthWrap: panel.querySelector('[data-kyc-jalali-date="' + prefix + '"]'),
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
            if (els.birthWrap && typeof els.birthWrap.__resetJalaliDate === 'function') {
                els.birthWrap.__resetJalaliDate();
            } else if (els.birthDate) {
                els.birthDate.value = '';
            }
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
        if (typeof window.__initJalaliDatepickers === 'function') {
            window.__initJalaliDatepickers(panel);
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
            if (!els.birthDate?.value) {
                setError(@json(__('kyc.birth_date')) + ' الزامی است.');
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
            fd.append('mobile', els.mobile?.value || '');
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
