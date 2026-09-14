@php
    $packageOptions = $packageOptions ?? [];
    $purchasePreviewUrl = route('admin.accounts.purchase-preview');
    $packageOptionsUrl = route('admin.accounts.package-options');
    $clientOptionsUrl = route('admin.accounts.client-options');
    $serverCatalog = ($servers ?? collect())->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values();
@endphp

<x-form.group label="مالک اکانت" hint="نماینده یا فروشنده‌ای که این اکانت به نام او ثبت می‌شود.">
    <select name="owner_seller_id" id="admin-owner-id" required class="form-control">
        @foreach ($accountOwners as $owner)
            <option value="{{ $owner->id }}" @selected(old('owner_seller_id', $accountOwners->first()?->id) == $owner->id)>
                {{ $owner->full_name }} ({{ $owner->role->label() }})
            </option>
        @endforeach
    </select>
</x-form.group>

@php
    $accountPackageGroups = collect();
    if (($packages ?? collect())->isNotEmpty()) {
        if (class_exists(\App\Services\PackageCategoryService::class)) {
            $accountPackageGroups = app(\App\Services\PackageCategoryService::class)->groupPackages($packages);
        } else {
            $accountPackageGroups = collect([['label' => '', 'packages' => $packages]]);
        }
    }
@endphp

<x-form.group label="{{ __('accounts.package') }}">
    <select name="package_id" id="admin-package-id" required class="form-control">
        <option value="">—</option>
        @foreach ($accountPackageGroups as $group)
            @if (! empty($group['label']))
                <optgroup label="{{ $group['label'] }}">
                    @foreach ($group['packages'] as $package)
                        <option value="{{ $package->id }}" @selected(old('package_id') == $package->id)>{{ $package->name }}</option>
                    @endforeach
                </optgroup>
            @else
                @foreach ($group['packages'] as $package)
                    <option value="{{ $package->id }}" @selected(old('package_id') == $package->id)>{{ $package->name }}</option>
                @endforeach
            @endif
        @endforeach
    </select>
</x-form.group>

<x-form.group :label="__('packages.select_duration')" :hint="__('packages.select_duration_hint')">
    <select name="package_duration_id" id="admin-duration-id" required class="form-control">
        <option value="">—</option>
    </select>
</x-form.group>

<div id="admin-data-gb-group" class="form-group col-md-12" hidden>
    <label class="form-label">{{ __('packages.choose_volume_gb') }}</label>
    <input type="number" name="data_gb" id="admin-data-gb" step="1" min="1" class="form-control" value="{{ old('data_gb') }}">
    <p class="help-block text-muted small mb-0" id="admin-data-gb-hint"></p>
</div>

@include('shared.accounts.partials.kyc-panel', [
    'kycIdPrefix' => 'admin',
])

<x-form.group label="سرور" hint="خالی = خودکار (کم‌ترافیک‌ترین سرور مجاز)">
    <select name="server_id" id="admin-server-id" class="form-control">
        <option value="">خودکار</option>
    </select>
</x-form.group>

@php
    $clientMode = old('client_mode', 'display_name');
@endphp

<x-form.group label="مشتری" wide>
    <div class="row g-2" role="group">
        <div class="col-sm-6">
            <input type="radio" class="btn-check" name="client_mode" id="admin-client-display" value="display_name" @checked($clientMode === 'display_name') autocomplete="off">
            <label class="btn btn-outline-primary w-100 py-2" for="admin-client-display">{{ __('accounts.client_mode_display_name') }}</label>
        </div>
        <div class="col-sm-6">
            <input type="radio" class="btn-check" name="client_mode" id="admin-client-existing" value="existing" @checked($clientMode === 'existing') autocomplete="off">
            <label class="btn btn-outline-primary w-100 py-2" for="admin-client-existing">{{ __('clients.client_mode_existing') }}</label>
        </div>
    </div>
</x-form.group>

<div id="admin-display-name-fields" @if($clientMode !== 'display_name') hidden @endif>
    <x-form.group label="{{ __('accounts.display_label') }}" hint="{{ __('accounts.display_label_hint') }}">
        <input name="account_display_name" id="admin-display-name" value="{{ old('account_display_name') }}" class="form-control" maxlength="255">
    </x-form.group>
    <p class="text-muted small">{{ __('accounts.admin_auto_credentials_hint') }}</p>
</div>

<div id="admin-existing-client-fields" @if($clientMode !== 'existing') hidden @endif>
    <x-form.group :label="__('clients.select_existing_client')">
        <select name="client_user_id" id="admin-client-user-id" class="form-control">
            <option value="">—</option>
        </select>
        <p class="form-text text-muted mb-0 mt-1" id="admin-client-list-empty" hidden>{{ __('clients.no_clients_yet') }}</p>
    </x-form.group>
</div>

<x-form.group label="{{ __('accounts.admin_custom_charge') }}" hint="{{ __('accounts.admin_custom_charge_hint') }}">
    <input
        type="number"
        name="admin_custom_charge"
        id="admin-custom-charge"
        class="form-control"
        min="0"
        step="1"
        value="{{ old('admin_custom_charge') }}"
        placeholder="{{ __('accounts.admin_custom_charge_placeholder') }}"
    >
    <div class="form-check mt-2">
        <input type="hidden" name="admin_free_account" value="0">
        <input
            type="checkbox"
            name="admin_free_account"
            id="admin-free-account"
            class="form-check-input"
            value="1"
            @checked(old('admin_free_account'))
        >
        <label class="form-check-label" for="admin-free-account">{{ __('accounts.admin_free_account') }}</label>
        <small class="text-muted d-block">{{ __('accounts.admin_free_account_hint') }}</small>
    </div>
</x-form.group>

<div id="admin-purchase-pricing" class="col-12 mb-3" hidden>
    <div class="card border border-success">
        <div class="card-body py-3">
            <h6 class="card-title mb-3">{{ __('accounts.admin_pricing_preview') }}</h6>
            <div class="row g-2 small">
                <div class="col-md-4">
                    <span class="text-muted d-block">{{ __('accounts.wholesale_reference') }}</span>
                    <strong id="admin-pricing-wholesale">—</strong>
                </div>
                <div class="col-md-4">
                    <span class="text-muted d-block">{{ __('accounts.charged_amount') }}</span>
                    <strong id="admin-pricing-charge" class="text-primary">—</strong>
                </div>
                <div class="col-md-4">
                    <span class="text-muted d-block">{{ __('accounts.agent_margin_preview') }}</span>
                    <strong id="admin-pricing-margin">—</strong>
                </div>
            </div>
            <p class="mb-0 mt-2 text-danger small" id="admin-pricing-error" hidden></p>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const packageOptionsUrl = @json($packageOptionsUrl);
    const purchasePreviewUrl = @json($purchasePreviewUrl);
    const clientOptionsUrl = @json($clientOptionsUrl);
    const serverCatalog = @json($serverCatalog);
    let packageOptions = @json($packageOptions);
    let optionsById = Object.fromEntries((packageOptions || []).map(function (p) { return [String(p.id), p]; }));

    const ownerSelect = document.getElementById('admin-owner-id');
    const packageSelect = document.getElementById('admin-package-id');
    const durationSelect = document.getElementById('admin-duration-id');
    const serverSelect = document.getElementById('admin-server-id');
    const gbGroup = document.getElementById('admin-data-gb-group');
    const gbInput = document.getElementById('admin-data-gb');
    const gbHint = document.getElementById('admin-data-gb-hint');
    const customChargeInput = document.getElementById('admin-custom-charge');
    const pricingBox = document.getElementById('admin-purchase-pricing');
    const pricingWholesale = document.getElementById('admin-pricing-wholesale');
    const pricingCharge = document.getElementById('admin-pricing-charge');
    const pricingMargin = document.getElementById('admin-pricing-margin');
    const pricingError = document.getElementById('admin-pricing-error');
    const modeDisplay = document.getElementById('admin-client-display');
    const modeExisting = document.getElementById('admin-client-existing');
    const displayFields = document.getElementById('admin-display-name-fields');
    const existingFields = document.getElementById('admin-existing-client-fields');
    const displayNameInput = document.getElementById('admin-display-name');
    const clientSelect = document.getElementById('admin-client-user-id');
    const clientListEmpty = document.getElementById('admin-client-list-empty');

    let packageRequestId = 0;
    let previewRequestId = 0;
    let clientRequestId = 0;

    // Prices are formatted client-side, so the currency has to come from the package
    // (or the preview payload) rather than being assumed to be Toman.
    function currentPackageMeta() {
        return optionsById[String(packageSelect.value)] || {};
    }

    function formatMoney(value, meta) {
        meta = meta || {};
        const decimals = Number.isFinite(meta.currency_decimals) ? meta.currency_decimals : 0;
        const suffix = meta.currency_symbol || meta.currency_label || 'تومان';

        return Number(value).toLocaleString('fa-IR', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }) + ' ' + suffix;
    }

    // Kept so every existing call site becomes currency-aware without being touched.
    function formatToman(value) {
        return formatMoney(value, currentPackageMeta());
    }

    function toggleClientMode() {
        const existing = modeExisting && modeExisting.checked;
        if (displayFields) displayFields.hidden = existing;
        if (existingFields) existingFields.hidden = !existing;
        if (displayNameInput) {
            if (existing) displayNameInput.removeAttribute('required');
            else displayNameInput.setAttribute('required', 'required');
        }
        if (clientSelect) {
            if (existing) clientSelect.setAttribute('required', 'required');
            else clientSelect.removeAttribute('required');
        }
    }

    function loadClients() {
        if (!clientSelect) return;
        const params = new URLSearchParams();
        if (ownerSelect && ownerSelect.value) params.set('owner_seller_id', ownerSelect.value);
        const requestId = ++clientRequestId;
        fetch(clientOptionsUrl + '?' + params.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (payload) {
                if (requestId !== clientRequestId) return;
                clientSelect.innerHTML = '<option value="">—</option>';
                const clients = payload.clients || [];
                clients.forEach(function (c) {
                    const opt = document.createElement('option');
                    opt.value = c.id;
                    opt.textContent = c.label;
                    if (String(c.id) === @json(old('client_user_id'))) opt.selected = true;
                    clientSelect.appendChild(opt);
                });
                if (clientListEmpty) clientListEmpty.hidden = clients.length > 0;
            })
            .catch(function () {});
    }

    function rebuildPackageSelect(groups) {
        packageSelect.innerHTML = '<option value="">—</option>';
        (groups || []).forEach(function (group) {
            const optgroup = document.createElement('optgroup');
            optgroup.label = group.label || '';
            (group.packages || []).forEach(function (p) {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.name;
                if (String(p.id) === @json(old('package_id'))) opt.selected = true;
                optgroup.appendChild(opt);
            });
            packageSelect.appendChild(optgroup);
        });
    }

    function loadPackageOptions() {
        const params = new URLSearchParams();
        if (ownerSelect && ownerSelect.value) params.set('owner_seller_id', ownerSelect.value);
        const requestId = ++packageRequestId;
        fetch(packageOptionsUrl + '?' + params.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
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

    function hidePricing() {
        if (pricingBox) pricingBox.hidden = true;
        if (pricingError) pricingError.hidden = true;
    }

    function loadPricingPreview() {
        const durationId = durationSelect.value;
        const pkg = optionsById[String(packageSelect.value)] || null;
        if (!durationId) { hidePricing(); return; }

        const params = new URLSearchParams({ package_duration_id: durationId });
        if (ownerSelect && ownerSelect.value) params.set('owner_seller_id', ownerSelect.value);
        if (pkg && pkg.is_elastic && gbInput) {
            const minGb = pkg.min_gb || 1;
            params.set('data_gb', gbInput.value && Number(gbInput.value) > 0 ? gbInput.value : String(minGb));
        }
        if (customChargeInput && customChargeInput.value !== '') {
            params.set('admin_custom_charge', customChargeInput.value);
        }
        // The preview must see the free checkbox too, otherwise it quotes the wholesale
        // price while the submitted form creates a free account (or the reverse).
        const freeAccountInput = document.getElementById('admin-free-account');
        if (freeAccountInput && freeAccountInput.checked) {
            params.set('admin_free_account', '1');
        }

        const requestId = ++previewRequestId;
        fetch(purchasePreviewUrl + '?' + params.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
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
                pricingWholesale.textContent = formatMoney(result.payload.wholesale_price, result.payload);
                pricingCharge.textContent = formatMoney(result.payload.final_charge, result.payload);
                pricingMargin.textContent = Number(result.payload.agent_margin) > 0
                    ? formatMoney(result.payload.agent_margin, result.payload)
                    : '—';
            })
            .catch(function () { hidePricing(); });
    }

    function refreshPackageFields() {
        const pkg = optionsById[String(packageSelect.value)] || null;
        durationSelect.innerHTML = '<option value="">—</option>';
        if (serverSelect) serverSelect.innerHTML = '<option value="">خودکار</option>';
        hidePricing();

        if (window.__kycPanel_admin) {
            window.__kycPanel_admin.syncPackage(pkg, optionsById);
        }

        if (gbGroup) {
            gbGroup.hidden = !(pkg && pkg.is_elastic);
            if (pkg && pkg.is_elastic && gbInput) {
                const min = pkg.min_gb || 1;
                const max = pkg.max_gb;
                gbInput.min = min;
                if (max) gbInput.max = max; else gbInput.removeAttribute('max');
                if (!gbInput.value || Number(gbInput.value) < min) gbInput.value = min;
                if (gbHint) {
                    gbHint.textContent = max
                        ? '{{ __('packages.gb_range_hint') }}'.replace(':min', min).replace(':max', max)
                        : '{{ __('packages.gb_min_hint') }}'.replace(':min', min);
                }
            }
        }

        if (!pkg) return;

        pkg.durations.forEach(function (d) {
            const opt = document.createElement('option');
            opt.value = d.id;
            opt.textContent = d.label + (d.is_test ? ' ({{ __('packages.test_badge') }})' : '');
            if (String(d.id) === @json(old('package_duration_id'))) opt.selected = true;
            durationSelect.appendChild(opt);
        });

        if (serverSelect) {
            serverCatalog.forEach(function (s) {
                if (!pkg.server_ids.includes(s.id)) return;
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = s.name;
                if (String(s.id) === @json(old('server_id'))) opt.selected = true;
                serverSelect.appendChild(opt);
            });
        }

        loadPricingPreview();
    }

    if (modeDisplay) modeDisplay.addEventListener('change', toggleClientMode);
    if (modeExisting) modeExisting.addEventListener('change', toggleClientMode);
    if (ownerSelect) {
        ownerSelect.addEventListener('change', function () {
            loadPackageOptions();
            loadClients();
        });
    }
    if (packageSelect) packageSelect.addEventListener('change', refreshPackageFields);
    if (durationSelect) durationSelect.addEventListener('change', loadPricingPreview);
    if (gbInput) gbInput.addEventListener('change', loadPricingPreview);
    if (customChargeInput) customChargeInput.addEventListener('input', loadPricingPreview);

    toggleClientMode();
    loadClients();
    refreshPackageFields();
})();
</script>
@include('shared.accounts.partials.kyc-panel-script', [
    'kycIdPrefix' => 'admin',
    'kycSubmitUrl' => route('admin.accounts.kyc.submit'),
    'kycVerifyUrlTemplate' => str_replace('999999', '__ID__', route('admin.accounts.kyc.verify', ['kycVerification' => 999999])),
    'kycResetUrlTemplate' => str_replace('999999', '__ID__', route('admin.accounts.kyc.request-reset', ['kycVerification' => 999999])),
    'kycSubmitButtonId' => 'admin-create-submit',
])
@endpush
