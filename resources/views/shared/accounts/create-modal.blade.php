@php
    $prefix = $prefix ?? 'agent';
    $accountOwners = $accountOwners ?? collect();
    $isAgent = $prefix === 'agent';
    $clientMode = old('client_mode', 'auto');
    $storeUrl = route("{$prefix}.accounts.store");
    $packageOptionsUrl = route("{$prefix}.accounts.package-options");
    $clientOptionsUrl = route("{$prefix}.accounts.client-options");
    $purchasePreviewUrl = route("{$prefix}.accounts.purchase-preview");
@endphp

<div class="modal staff-create-modal" id="staff-create-account-modal" tabindex="-1" role="dialog" aria-labelledby="staff-create-account-title" @if(empty($openCreateModal)) hidden @endif>
    <div class="modal-dialog staff-create-modal__dialog" role="document">
        <div class="modal-content staff-create-modal__content">
            <div class="modal-header">
                <h5 class="modal-title" id="staff-create-account-title">{{ __('accounts.create') }}</h5>
                <button type="button" class="btn-close staff-create-modal__close" aria-label="{{ __('app.cancel') }}"></button>
            </div>
            <form method="POST" action="{{ $storeUrl }}" id="staff-create-account-form">
                @csrf
                <div class="modal-body">
                    @if ($isAgent && $accountOwners->isNotEmpty())
                    <div class="mb-3">
                        <label class="form-label">{{ __('accounts.owner') }}</label>
                        <select name="owner_seller_id" id="staff-owner-id" class="form-control" required>
                            @foreach ($accountOwners as $owner)
                                <option value="{{ $owner->id }}" @selected(old('owner_seller_id', auth()->id()) == $owner->id)>
                                    {{ $owner->full_name ?: $owner->username }} ({{ $owner->role->label() }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    @endif

                    <div class="mb-3">
                        <label class="form-label" for="staff-display-name">{{ __('accounts.display_label') }}</label>
                        <input type="text" name="account_display_name" id="staff-display-name" class="form-control" required maxlength="255" value="{{ old('account_display_name') }}" placeholder="{{ __('accounts.staff_display_name_placeholder') }}">
                    </div>

                    <div class="mb-3">
                        <label class="form-label d-block">{{ __('accounts.staff_client_mode') }}</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="radio" class="btn-check" name="client_mode" id="staff-client-auto" value="auto" @checked($clientMode === 'auto') autocomplete="off">
                                <label class="btn btn-outline-primary w-100 py-2" for="staff-client-auto">{{ __('accounts.staff_client_auto') }}</label>
                            </div>
                            <div class="col-6">
                                <input type="radio" class="btn-check" name="client_mode" id="staff-client-existing" value="existing" @checked($clientMode === 'existing') autocomplete="off">
                                <label class="btn btn-outline-primary w-100 py-2" for="staff-client-existing">{{ __('accounts.staff_client_existing') }}</label>
                            </div>
                        </div>
                        <p class="form-text text-muted mb-0 mt-2" id="staff-client-auto-hint">{{ __('accounts.staff_client_auto_hint') }}</p>
                    </div>

                    <div class="mb-3" id="staff-client-select-wrap" @if($clientMode !== 'existing') hidden @endif>
                        <label class="form-label" for="staff-client-user-id">{{ __('clients.select_existing_client') }}</label>
                        <select name="client_user_id" id="staff-client-user-id" class="form-control">
                            <option value="">—</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="staff-package-id">{{ __('accounts.package') }}</label>
                        <select name="package_id" id="staff-package-id" class="form-control" required>
                            <option value="">—</option>
                        </select>
                    </div>

                    <div id="staff-duration-wrap" class="mb-3" hidden>
                        <label class="form-label" for="staff-duration-id">{{ __('packages.select_duration') }}</label>
                        <select name="package_duration_id" id="staff-duration-id" class="form-control" required disabled>
                            <option value="">—</option>
                        </select>
                        <p class="form-text text-muted mb-0 mt-1" id="staff-fixed-duration-hint"></p>
                    </div>

                    <div id="staff-data-gb-wrap" class="mb-3" hidden>
                        <label class="form-label" for="staff-data-gb">{{ __('packages.choose_volume_gb') }}</label>
                        <input type="number" name="data_gb" id="staff-data-gb" class="form-control" step="1" min="1" value="{{ old('data_gb') }}">
                        <p class="form-text text-muted mb-0 mt-1" id="staff-data-gb-hint"></p>
                    </div>

                    @include('shared.accounts.partials.kyc-panel', [
                        'kycIdPrefix' => 'staff',
                    ])

                    <div id="staff-pricing-box" class="staff-create-pricing" hidden>
                        <div class="staff-create-pricing__inner">
                            <div class="staff-create-pricing__row">
                                <span class="text-muted">{{ __('accounts.wholesale_reference') }}</span>
                                <strong id="staff-pricing-wholesale">—</strong>
                            </div>
                            <div class="staff-create-pricing__row">
                                <span class="text-muted">{{ __('accounts.charged_amount') }}</span>
                                <strong id="staff-pricing-charge" class="text-primary">—</strong>
                            </div>
                            <p class="mb-0 mt-2 text-danger small" id="staff-pricing-error" hidden></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary staff-create-modal__close">{{ __('app.cancel') }}</button>
                    <button type="submit" class="btn btn-primary" id="staff-create-submit">
                        <i class="bx bx-plus-circle"></i> {{ __('accounts.create') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const modal = document.getElementById('staff-create-account-modal');
    const form = document.getElementById('staff-create-account-form');
    if (!modal || !form) return;

    const packageOptionsUrl = @json($packageOptionsUrl);
    const clientOptionsUrl = @json($clientOptionsUrl);
    const purchasePreviewUrl = @json($purchasePreviewUrl);
    const openOnLoad = @json(!empty($openCreateModal));
    const oldDurationId = @json(old('package_duration_id'));
    const oldPackageId = @json(old('package_id'));
    const oldClientId = @json(old('client_user_id'));

    const ownerSelect = document.getElementById('staff-owner-id');
    const packageSelect = document.getElementById('staff-package-id');
    const durationWrap = document.getElementById('staff-duration-wrap');
    const durationSelect = document.getElementById('staff-duration-id');
    const durationHint = document.getElementById('staff-fixed-duration-hint');
    const gbWrap = document.getElementById('staff-data-gb-wrap');
    const gbInput = document.getElementById('staff-data-gb');
    const gbHint = document.getElementById('staff-data-gb-hint');
    const pricingBox = document.getElementById('staff-pricing-box');
    const pricingWholesale = document.getElementById('staff-pricing-wholesale');
    const pricingCharge = document.getElementById('staff-pricing-charge');
    const pricingError = document.getElementById('staff-pricing-error');
    const clientAuto = document.getElementById('staff-client-auto');
    const clientExisting = document.getElementById('staff-client-existing');
    const clientSelectWrap = document.getElementById('staff-client-select-wrap');
    const clientSelect = document.getElementById('staff-client-user-id');
    const clientAutoHint = document.getElementById('staff-client-auto-hint');

    let packageOptions = [];
    let optionsById = {};
    let packageRequestId = 0;
    let clientRequestId = 0;
    let previewRequestId = 0;
    let pricingDebounceTimer = null;

    function schedulePricingPreview() {
        if (pricingDebounceTimer) clearTimeout(pricingDebounceTimer);
        pricingDebounceTimer = setTimeout(loadPricingPreview, 200);
    }

    function formatMoney(value, currencyMeta) {
        const meta = currencyMeta || {};
        const decimals = Number.isFinite(Number(meta.decimals)) ? Number(meta.decimals) : 0;
        const symbol = meta.symbol || meta.label || @json(__('packages.toman'));
        const amount = Number(value);
        if (!Number.isFinite(amount)) {
            return '—';
        }
        return amount.toLocaleString('fa-IR', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }) + ' ' + symbol;
    }

    function packageCurrencyMeta(pkg) {
        if (!pkg) {
            return { symbol: @json(__('packages.toman')), label: @json(__('packages.toman')), decimals: 0 };
        }
        return {
            code: pkg.currency || 'IRT',
            symbol: pkg.currency_symbol || pkg.currency_label || @json(__('packages.toman')),
            label: pkg.currency_label || pkg.currency_symbol || @json(__('packages.toman')),
            decimals: Number.isFinite(Number(pkg.currency_decimals)) ? Number(pkg.currency_decimals) : 0,
        };
    }

    function formatToman(value) {
        return formatMoney(value, { symbol: @json(__('packages.toman')), decimals: 0 });
    }

    function openModal() {
        modal.hidden = false;
        modal.classList.add('show');
        document.body.classList.add('staff-create-modal-open');
    }

    function closeModal() {
        modal.hidden = true;
        modal.classList.remove('show');
        document.body.classList.remove('staff-create-modal-open');
    }

    document.querySelectorAll('[data-staff-create-account-open]').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            event.preventDefault();
            openModal();
            loadPackageOptions();
            loadClients();
        });
    });

    modal.querySelectorAll('.staff-create-modal__close').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            event.preventDefault();
            closeModal();
        });
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('show')) closeModal();
    });

    function toggleClientMode() {
        const existing = clientExisting && clientExisting.checked;
        if (clientSelectWrap) clientSelectWrap.hidden = !existing;
        if (clientSelect) {
            if (existing) clientSelect.setAttribute('required', 'required');
            else clientSelect.removeAttribute('required');
        }
        if (clientAutoHint) {
            clientAutoHint.hidden = existing;
        }
    }

    function rebuildPackageSelect(groups) {
        packageSelect.innerHTML = '<option value="">—</option>';
        (groups || []).forEach(function (group) {
            const optgroup = document.createElement('optgroup');
            optgroup.label = group.label || '';
            (group.packages || []).forEach(function (pkg) {
                const opt = document.createElement('option');
                opt.value = pkg.id;
                opt.textContent = pkg.name;
                if (String(pkg.id) === String(oldPackageId)) opt.selected = true;
                optgroup.appendChild(opt);
            });
            packageSelect.appendChild(optgroup);
        });
    }

    function loadPackageOptions() {
        const params = new URLSearchParams();
        if (ownerSelect && ownerSelect.value) params.set('owner_seller_id', ownerSelect.value);
        const requestId = ++packageRequestId;
        fetch(packageOptionsUrl + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (payload) {
                if (requestId !== packageRequestId) return;
                packageOptions = payload.packageOptions || [];
                optionsById = Object.fromEntries(packageOptions.map(function (p) { return [String(p.id), p]; }));
                rebuildPackageSelect(payload.packageGroups || []);
                refreshPackageFields();
            })
            .catch(function () {});
    }

    function loadClients() {
        if (!clientSelect) return;
        const params = new URLSearchParams();
        if (ownerSelect && ownerSelect.value) params.set('owner_seller_id', ownerSelect.value);
        const requestId = ++clientRequestId;
        fetch(clientOptionsUrl + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (payload) {
                if (requestId !== clientRequestId) return;
                clientSelect.innerHTML = '<option value="">—</option>';
                (payload.clients || []).forEach(function (client) {
                    const opt = document.createElement('option');
                    opt.value = client.id;
                    opt.textContent = client.label;
                    if (String(client.id) === String(oldClientId)) opt.selected = true;
                    clientSelect.appendChild(opt);
                });
            })
            .catch(function () {});
    }

    function hidePricing() {
        if (pricingBox) pricingBox.hidden = true;
        if (pricingError) pricingError.hidden = true;
    }

    function loadPricingPreview() {
        const durationId = durationSelect.value;
        const pkg = optionsById[String(packageSelect.value)] || null;
        if (!durationId || !pkg) { hidePricing(); return; }

        const params = new URLSearchParams({ package_duration_id: durationId });
        if (ownerSelect && ownerSelect.value) params.set('owner_seller_id', ownerSelect.value);
        if (pkg.is_elastic && gbInput) {
            const minGb = pkg.min_gb || 1;
            params.set('data_gb', gbInput.value && Number(gbInput.value) > 0 ? gbInput.value : String(minGb));
        }

        const requestId = ++previewRequestId;
        fetch(purchasePreviewUrl + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (response) {
                return response.json().then(function (payload) {
                    return { ok: response.ok, payload: payload };
                });
            })
            .then(function (result) {
                if (requestId !== previewRequestId) return;
                if (!result.ok) {
                    pricingBox.hidden = false;
                    pricingError.hidden = false;
                    pricingError.textContent = result.payload.error || @json(__('accounts.purchase_preview_failed'));
                    return;
                }
                pricingBox.hidden = false;
                pricingError.hidden = true;
                const currencyMeta = {
                    code: result.payload.currency,
                    symbol: result.payload.currency_symbol || result.payload.currency_label,
                    label: result.payload.currency_label || result.payload.currency_symbol,
                    decimals: result.payload.currency_decimals,
                };
                pricingWholesale.textContent = formatMoney(result.payload.wholesale_price, currencyMeta);
                pricingCharge.textContent = formatMoney(result.payload.final_charge, currencyMeta);
            })
            .catch(function () { hidePricing(); });
    }

    function refreshPackageFields() {
        const pkg = optionsById[String(packageSelect.value)] || null;
        durationSelect.innerHTML = '<option value="">—</option>';
        hidePricing();

        if (window.__kycPanel_staff) {
            window.__kycPanel_staff.syncPackage(pkg, optionsById);
        }

        if (!pkg) {
            durationWrap.hidden = true;
            gbWrap.hidden = true;
            durationSelect.disabled = true;
            if (gbInput) gbInput.removeAttribute('required');
            return;
        }

        durationWrap.hidden = false;
        durationSelect.disabled = false;
        gbWrap.hidden = !pkg.is_elastic;

        if (pkg.is_elastic && gbInput) {
            gbInput.setAttribute('required', 'required');
            const min = pkg.min_gb || 1;
            const max = pkg.max_gb;
            gbInput.min = min;
            if (max) gbInput.max = max; else gbInput.removeAttribute('max');
            if (!gbInput.value || Number(gbInput.value) < min) gbInput.value = min;
            if (gbHint) {
                gbHint.textContent = max
                    ? @json(__('packages.gb_range_hint')).replace(':min', min).replace(':max', max)
                    : @json(__('packages.gb_min_hint')).replace(':min', min);
            }
            durationHint.textContent = '';
        } else if (gbInput) {
            gbInput.removeAttribute('required');
        }

        const enabledDurations = pkg.durations || [];
        durationSelect.classList.remove('d-none');

        if (!pkg.is_elastic && enabledDurations.length === 1) {
            const only = enabledDurations[0];
            const opt = document.createElement('option');
            opt.value = only.id;
            opt.textContent = only.label;
            opt.selected = true;
            durationSelect.appendChild(opt);
            durationSelect.classList.add('d-none');
            durationHint.textContent = only.label + ' — ' + formatMoney(only.price, packageCurrencyMeta(pkg));
            loadPricingPreview();
            return;
        }

        enabledDurations.forEach(function (d) {
            const opt = document.createElement('option');
            opt.value = d.id;
            opt.textContent = d.label + ' — ' + formatMoney(d.price, packageCurrencyMeta(pkg)) + (d.is_test ? ' ({{ __('packages.test_badge') }})' : '');
            if (String(d.id) === String(oldDurationId)) opt.selected = true;
            durationSelect.appendChild(opt);
        });

        if (!pkg.is_elastic) {
            durationHint.textContent = @json(__('accounts.staff_fixed_duration_hint'));
        }

        loadPricingPreview();
    }

    if (ownerSelect) {
        ownerSelect.addEventListener('change', function () {
            loadPackageOptions();
            loadClients();
        });
    }
    if (packageSelect) packageSelect.addEventListener('change', refreshPackageFields);
    if (durationSelect) durationSelect.addEventListener('change', loadPricingPreview);
    if (gbInput) {
        gbInput.addEventListener('input', schedulePricingPreview);
        gbInput.addEventListener('change', loadPricingPreview);
    }
    if (clientAuto) clientAuto.addEventListener('change', toggleClientMode);
    if (clientExisting) clientExisting.addEventListener('change', toggleClientMode);

    toggleClientMode();
    if (openOnLoad) {
        openModal();
        loadPackageOptions();
        loadClients();
    }
})();
</script>
@include('shared.accounts.partials.kyc-panel-script', [
    'kycIdPrefix' => 'staff',
    'kycSubmitUrl' => route("{$prefix}.accounts.kyc.submit"),
    'kycVerifyUrlTemplate' => str_replace('999999', '__ID__', route("{$prefix}.accounts.kyc.verify", ['kycVerification' => 999999])),
    'kycResetUrlTemplate' => str_replace('999999', '__ID__', route("{$prefix}.accounts.kyc.request-reset", ['kycVerification' => 999999])),
    'kycSubmitButtonId' => 'staff-create-submit',
])
@endpush
