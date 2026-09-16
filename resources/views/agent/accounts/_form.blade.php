@php
    $account = $account ?? null;
    $autoServer = $autoServer ?? null;
    $canTransferServer = $canTransferServer ?? false;
    $packageOptions = $packageOptions ?? [];
    $servers = $servers ?? collect();
    $purchasePricingLabels = [
        'title' => __('ui.purchase_price_calc'),
        'list_price' => __('ui.wholesale_price'),
        'final' => __('ui.final_amount_wallet_deduction'),
        'margin_hint' => __('ui.agent_margin_hint'),
        'discount_hint' => __('ui.global_discount_applied'),
        'preview_failed' => __('ui.price_calc_failed'),
    ];
    $routePanel = explode('.', request()->route()?->getName() ?? '')[0] ?: 'admin';
    $canSelectServerOnCreate = ! $account && $routePanel === 'admin';
@endphp

@if (isset($accountOwners) || isset($sellers))
@php $accountOwners = $accountOwners ?? $sellers ?? collect(); @endphp
<x-form.group label="{{ __('ui.account_owner_label') }}" hint="{{ __('ui.account_owner_hint_agent') }}">
    <select name="owner_seller_id" required class="form-control">
        @foreach ($accountOwners as $owner)
            <option value="{{ $owner->id }}" @selected(old('owner_seller_id') == $owner->id)>
                {{ $owner->full_name }} ({{ $owner->role->label() }})
            </option>
        @endforeach
    </select>
</x-form.group>
@endif

@if (isset($packages) && ! $account)
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
    <select name="package_id" id="account-package-id" required class="form-control">
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
    <select name="package_duration_id" id="account-duration-id" required class="form-control">
        <option value="">—</option>
    </select>
</x-form.group>
<div id="account-data-gb-group" class="form-group col-md-12" hidden>
    <label class="form-label">{{ __('packages.choose_volume_gb') }}</label>
    <input type="number" name="data_gb" id="account-data-gb" step="1" min="1" class="form-control">
    <p class="help-block text-muted small mb-0" id="account-data-gb-hint"></p>
</div>
<div id="account-purchase-pricing" class="col-12 mb-3" hidden>
    <div class="card border border-success">
        <div class="card-body py-3">
            <h6 class="card-title mb-3">{{ $purchasePricingLabels['title'] }}</h6>
            <div class="row g-2 small">
                <div class="col-md-6">
                    <span class="text-muted d-block">{{ $purchasePricingLabels['list_price'] }}</span>
                    <strong id="pricing-list-price">—</strong>
                </div>
                <div class="col-md-6">
                    <span class="text-muted d-block">{{ $purchasePricingLabels['final'] }}</span>
                    <strong id="pricing-final" class="text-primary">—</strong>
                </div>
            </div>
            <p class="mb-0 mt-2 text-muted small" id="pricing-margin-hint"></p>
            <p class="mb-0 mt-1 text-muted small" id="pricing-discount-hint"></p>
            <p class="mb-0 mt-1 text-danger small" id="pricing-error" hidden></p>
        </div>
    </div>
</div>
@endif

@if ($canSelectServerOnCreate && isset($servers))
<x-form.group label="{{ __('accounts.server') }}" hint="{{ __('ui.server_hint_package_only') }}">
    <select name="server_id" id="account-server-id" class="form-control">
        <option value="">{{ __('ui.server_auto_option') }}</option>
    </select>
</x-form.group>
@endif

@if ($account && isset($servers) && $canTransferServer)
<x-form.group label="{{ __('ui.server_transfer_label') }}" hint="{{ __('ui.server_transfer_hint') }}">
    <select name="server_id" class="form-control">
        @foreach ($servers as $server)
            <option value="{{ $server->id }}" @selected(old('server_id', $account->server_id) == $server->id)>{{ $server->name }}</option>
        @endforeach
    </select>
</x-form.group>
@elseif ($account && $account->server)
<x-form.static label="{{ __('accounts.server') }}" :value="$account->server->name" />
@endif

@if ($account && $account->packageDuration)
<x-form.group :label="__('packages.select_duration')">
    <p class="form-control-static">
        {{ $account->packageDuration->displayLabel() }}
        @if ($account->packageDuration->tier->isTest())
            <span class="text-warning">({{ __('packages.test_badge') }})</span>
        @endif
    </p>
</x-form.group>
@endif

@if ($account && $account->service_type->isSanaei())
<x-form.static :label="__('accounts.purchased_volume')" :value="$account->purchasedVolumeLabel()" />
@if (! $account->isUnlimited())
<x-form.static :label="__('accounts.data_consumed')" :value="format_data_size($account->data_used_bytes)" />
@endif
@endif

@if ($account)
<x-form.group :label="__('validation.attributes.remote_username')">
    <input
        name="remote_username"
        value="{{ old('remote_username', $account->remote_username) }}"
        required
        class="form-control"
        pattern="[a-zA-Z0-9_-]+"
        minlength="1"
        maxlength="255"
        autocomplete="off"
        dir="ltr"
    >
    <p class="help-block text-muted small mb-0">{{ __('accounts.service_username_hint') }}</p>
</x-form.group>
<x-form.group :label="__('ui.x_of_client', [':field' => __('auth.email')])">
    <input name="client_email" type="email" value="{{ old('client_email', $account->client_email) }}" class="form-control">
</x-form.group>
@else
@php
    $clientMode = old('client_mode', request('client_mode', 'new'));
    $clientOptionsUrl = route("{$routePanel}.accounts.client-options");
@endphp
<x-form.group :label="__('clients.buyer_credentials')" wide>
    <div class="row g-2 client-mode-toggle" role="group" aria-label="{{ __('clients.buyer_credentials') }}">
        <div class="col-sm-6">
            <input type="radio" class="btn-check" name="client_mode" id="client-mode-new" value="new" @checked($clientMode === 'new') autocomplete="off">
            <label class="btn btn-outline-primary w-100 d-flex align-items-center justify-content-center gap-1 py-2" for="client-mode-new">
                <i class="bx bx-user-plus" aria-hidden="true"></i>
                <span>{{ __('clients.client_mode_new') }}</span>
            </label>
        </div>
        <div class="col-sm-6">
            <input type="radio" class="btn-check" name="client_mode" id="client-mode-existing" value="existing" @checked($clientMode === 'existing') autocomplete="off">
            <label class="btn btn-outline-primary w-100 d-flex align-items-center justify-content-center gap-1 py-2" for="client-mode-existing">
                <i class="bx bx-list-ul" aria-hidden="true"></i>
                <span>{{ __('clients.client_mode_existing') }}</span>
            </label>
        </div>
    </div>
</x-form.group>
<div id="client-new-fields">
    <x-form.group label="{{ __('ui.buyer_username_label') }}" hint="{{ __('ui.buyer_username_hint') }}">
        <input name="client_username" id="client-username" value="{{ old('client_username') }}" class="form-control" autocomplete="off">
    </x-form.group>
    @php
        $clientPasswordMode = old('client_password_mode', 'auto');
    @endphp
    <x-form.group :label="__('accounts.client_password_mode')">
        <div class="row g-2 client-password-mode-toggle" role="group" aria-label="{{ __('accounts.client_password_mode') }}">
            <div class="col-sm-6">
                <input type="radio" class="btn-check" name="client_password_mode" id="client-password-mode-auto" value="auto" @checked($clientPasswordMode === 'auto') autocomplete="off">
                <label class="btn btn-outline-secondary w-100 py-2" for="client-password-mode-auto">{{ __('accounts.client_password_auto') }}</label>
            </div>
            <div class="col-sm-6">
                <input type="radio" class="btn-check" name="client_password_mode" id="client-password-mode-manual" value="manual" @checked($clientPasswordMode === 'manual') autocomplete="off">
                <label class="btn btn-outline-secondary w-100 py-2" for="client-password-mode-manual">{{ __('accounts.client_password_manual') }}</label>
            </div>
        </div>
        <p class="form-text text-muted mb-2 mt-2" id="client-password-mode-hint">{{ __('accounts.client_password_auto_hint') }}</p>
        <div class="input-group">
            <input
                name="client_password"
                id="client-password-new"
                type="text"
                class="form-control font-monospace"
                value="{{ old('client_password') }}"
                minlength="8"
                maxlength="255"
                autocomplete="off"
                pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,255}"
                title="{{ __('accounts.client_password_strength') }}"
            >
            <button type="button" class="btn btn-outline-secondary" id="client-password-regenerate" hidden>{{ __('accounts.client_password_regenerate') }}</button>
        </div>
    </x-form.group>
    <x-form.group label="{{ __('ui.buyer_full_name_label') }}">
        <input name="client_full_name" id="client-full-name" value="{{ old('client_full_name') }}" class="form-control">
    </x-form.group>
</div>
<div id="client-existing-fields" @if($clientMode !== 'existing') hidden @endif>
    <x-form.group :label="__('clients.select_existing_client')" :hint="__('clients.select_existing_client_hint')">
        <select name="client_user_id" id="client-user-id" class="form-control">
            <option value="">—</option>
        </select>
        <p class="form-text text-muted mb-0 mt-1" id="client-list-empty" hidden>{{ __('clients.no_clients_yet') }}</p>
    </x-form.group>
    <x-form.group :label="__('clients.optional_new_password')" :hint="__('clients.optional_new_password_hint')">
        <input name="client_password" id="client-password-existing" type="password" class="form-control" minlength="8" maxlength="255" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,255}" title="{{ __('accounts.client_password_strength') }}" autocomplete="new-password" @disabled($clientMode !== 'existing')>
    </x-form.group>
</div>
<div id="account-remote-username-group" class="col-md-12">
    <x-form.group :label="__('accounts.service_username')" :hint="__('accounts.service_username_hint')">
        <input
            name="remote_username"
            id="account-remote-username"
            value="{{ old('remote_username') }}"
            class="form-control"
            pattern="[a-zA-Z0-9_-]+"
            minlength="1"
            maxlength="255"
            autocomplete="off"
            dir="ltr"
            placeholder="user123"
        >
    </x-form.group>
</div>
<div id="sanaei-client-name-group" class="col-md-12" hidden>
    <x-form.group :label="__('accounts.sanaei_client_name')" :hint="__('accounts.sanaei_client_name_hint')" required>
        <input
            name="sanaei_client_name"
            id="sanaei-client-name"
            value="{{ old('sanaei_client_name') }}"
            class="form-control"
            pattern="[a-zA-Z0-9_-]+"
            minlength="1"
            maxlength="32"
            autocomplete="off"
            dir="ltr"
            placeholder="client123"
        >
    </x-form.group>
</div>
<div class="col-md-12"><p class="help-block" id="account-auto-username-hint">{{ __('accounts.auto_username_hint') }}</p></div>
@push('scripts')
<script>
(function () {
    const clientOptionsUrl = @json($clientOptionsUrl);
    const selectedClientId = @json(old('client_user_id', request('client_user_id')));
    const modeNew = document.getElementById('client-mode-new');
    const modeExisting = document.getElementById('client-mode-existing');
    const newFields = document.getElementById('client-new-fields');
    const existingFields = document.getElementById('client-existing-fields');
    const clientSelect = document.getElementById('client-user-id');
    const clientListEmpty = document.getElementById('client-list-empty');
    const ownerSelect = document.querySelector('[name="owner_seller_id"]');
    const usernameInput = document.getElementById('client-username');
    const passwordNew = document.getElementById('client-password-new');
    const passwordExisting = document.getElementById('client-password-existing');
    const fullNameInput = document.getElementById('client-full-name');
    const passwordModeAuto = document.getElementById('client-password-mode-auto');
    const passwordModeManual = document.getElementById('client-password-mode-manual');
    const passwordModeHint = document.getElementById('client-password-mode-hint');
    const passwordRegenerate = document.getElementById('client-password-regenerate');
    const passwordHints = {
        auto: @json(__('accounts.client_password_auto_hint')),
        manual: @json(__('accounts.client_password_manual_hint')),
    };
    if (!modeNew || !modeExisting) return;

    let loadRequestId = 0;

    const portalPasswordLower = 'abcdefghjkmnpqrstuvwxyz';
    const portalPasswordUpper = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    const portalPasswordDigits = '23456789';
    const portalPasswordAll = portalPasswordLower + portalPasswordUpper + portalPasswordDigits;

    function pickPortalPasswordChar(pool) {
        const cryptoObj = window.crypto || window.msCrypto;
        if (cryptoObj && cryptoObj.getRandomValues) {
            const bytes = new Uint8Array(1);
            cryptoObj.getRandomValues(bytes);
            return pool[bytes[0] % pool.length];
        }
        return pool[Math.floor(Math.random() * pool.length)];
    }

    function generatePortalPassword(length) {
        const chars = [
            pickPortalPasswordChar(portalPasswordLower),
            pickPortalPasswordChar(portalPasswordUpper),
            pickPortalPasswordChar(portalPasswordDigits),
        ];
        while (chars.length < length) {
            chars.push(pickPortalPasswordChar(portalPasswordAll));
        }
        for (let i = chars.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            const tmp = chars[i];
            chars[i] = chars[j];
            chars[j] = tmp;
        }
        return chars.join('');
    }

    function clientPasswordMode() {
        return passwordModeManual && passwordModeManual.checked ? 'manual' : 'auto';
    }

    function toggleClientPasswordMode() {
        if (!passwordNew) return;
        const manual = clientPasswordMode() === 'manual';
        if (passwordModeHint) {
            passwordModeHint.textContent = manual ? passwordHints.manual : passwordHints.auto;
        }
        if (passwordRegenerate) {
            passwordRegenerate.hidden = manual;
        }
        passwordNew.readOnly = !manual;
        passwordNew.minLength = 8;
        passwordNew.maxLength = manual ? 255 : 12;
        passwordNew.removeAttribute('inputmode');
        if (manual) {
            passwordNew.removeAttribute('required');
            passwordNew.type = 'password';
            if (!passwordNew.value) {
                passwordNew.value = '';
            }
        } else {
            passwordNew.removeAttribute('required');
            passwordNew.type = 'text';
            if (!passwordNew.value || passwordNew.value.length !== 12) {
                passwordNew.value = generatePortalPassword(12);
            }
        }
    }

    function currentMode() {
        return modeExisting.checked ? 'existing' : 'new';
    }

    function setFieldState(input, enabled, required) {
        if (!input) return;
        input.disabled = !enabled;
        if (required) {
            input.setAttribute('required', 'required');
        } else {
            input.removeAttribute('required');
        }
    }

    function toggleClientMode() {
        const existing = currentMode() === 'existing';
        newFields.hidden = existing;
        existingFields.hidden = !existing;

        setFieldState(usernameInput, !existing, !existing);
        setFieldState(passwordNew, !existing, false);
        if (!existing) {
            toggleClientPasswordMode();
        }
        setFieldState(fullNameInput, !existing, false);
        setFieldState(clientSelect, existing, existing);
        setFieldState(passwordExisting, existing, false);

        if (existing) {
            loadClientOptions();
        }
    }

    function renderClientOptions(clients) {
        if (!clientSelect) return;
        clientSelect.innerHTML = '<option value="">—</option>';
        clients.forEach(function (client) {
            const opt = document.createElement('option');
            opt.value = client.id;
            opt.textContent = client.label;
            if (String(client.id) === String(selectedClientId)) {
                opt.selected = true;
            }
            clientSelect.appendChild(opt);
        });
        if (clientListEmpty) {
            clientListEmpty.hidden = clients.length > 0;
        }
    }

    function loadClientOptions() {
        const params = new URLSearchParams();
        if (ownerSelect && ownerSelect.value) {
            params.set('owner_seller_id', ownerSelect.value);
        }

        const requestId = ++loadRequestId;
        fetch(clientOptionsUrl + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.json().then(function (payload) {
                    return { ok: response.ok, payload: payload };
                });
            })
            .then(function (result) {
                if (requestId !== loadRequestId) return;
                if (!result.ok) return;
                renderClientOptions(result.payload.clients || []);
            })
            .catch(function () {});
    }

    modeNew.addEventListener('change', toggleClientMode);
    modeExisting.addEventListener('change', toggleClientMode);
    if (passwordModeAuto) {
        passwordModeAuto.addEventListener('change', toggleClientPasswordMode);
    }
    if (passwordModeManual) {
        passwordModeManual.addEventListener('change', toggleClientPasswordMode);
    }
    if (passwordRegenerate) {
        passwordRegenerate.addEventListener('click', function () {
            if (clientPasswordMode() === 'auto' && passwordNew) {
                passwordNew.value = generatePortalPassword(12);
            }
        });
    }
    if (ownerSelect) {
        ownerSelect.addEventListener('change', function () {
            if (currentMode() === 'existing') {
                loadClientOptions();
            }
        });
    }

    toggleClientMode();
    toggleClientPasswordMode();
})();
</script>
@endpush
@endif

@if ($account)
<x-form.group :label="__('app.status')">
    <select name="status" class="form-control">
        @foreach (\App\Enums\AccountStatus::cases() as $status)
            <option value="{{ $status->value }}" @selected(old('status', $account->status?->value) === $status->value)>{{ $status->value }}</option>
        @endforeach
    </select>
</x-form.group>
@endif

@if (! $account)
@php
    $purchasePreviewUrl = route("{$routePanel}.accounts.purchase-preview");
    $packageOptionsUrl = route("{$routePanel}.accounts.package-options");
@endphp
@push('scripts')
<script>
(function () {
    let packageOptions = @json($packageOptions);
    const purchasePreviewUrl = @json($purchasePreviewUrl);
    const packageOptionsUrl = @json($packageOptionsUrl);
    const pricingLabels = @json($purchasePricingLabels);
    const packageSelect = document.getElementById('account-package-id');
    const durationSelect = document.getElementById('account-duration-id');
    const gbGroup = document.getElementById('account-data-gb-group');
    const gbInput = document.getElementById('account-data-gb');
    const gbHint = document.getElementById('account-data-gb-hint');
    const serverSelect = document.getElementById('account-server-id');
    const ownerSelect = document.querySelector('[name="owner_seller_id"]');
    const pricingBox = document.getElementById('account-purchase-pricing');
    const pricingList = document.getElementById('pricing-list-price');
    const pricingFinal = document.getElementById('pricing-final');
    const pricingDiscountHint = document.getElementById('pricing-discount-hint');
    const pricingMarginHint = document.getElementById('pricing-margin-hint');
    const pricingError = document.getElementById('pricing-error');
    const sanaeiNameGroup = document.getElementById('sanaei-client-name-group');
    const sanaeiNameInput = document.getElementById('sanaei-client-name');
    const remoteUsernameGroup = document.getElementById('account-remote-username-group');
    const remoteUsernameInput = document.getElementById('account-remote-username');
    const autoUsernameHint = document.getElementById('account-auto-username-hint');
    if (!packageSelect || !durationSelect) return;

    function isSanaeiPackage(pkg) {
        return pkg && String(pkg.service_type || '').indexOf('sanaei_') === 0;
    }

    function isNumericPppPackage(pkg) {
        if (!pkg || !pkg.service_type) return false;
        const type = String(pkg.service_type);
        return type === 'ppp' || type === 'l2tp' || type === 'openvpn';
    }

    function setSanaeiNameFieldState(active) {
        if (!sanaeiNameInput) return;
        sanaeiNameInput.disabled = !active;
        if (active) {
            sanaeiNameInput.setAttribute('required', 'required');
        } else {
            sanaeiNameInput.removeAttribute('required');
        }
    }

    function toggleSanaeiClientName(pkg) {
        const showSanaei = isSanaeiPackage(pkg);
        const autoPpp = isNumericPppPackage(pkg);
        if (sanaeiNameGroup) {
            sanaeiNameGroup.hidden = !showSanaei;
        }
        setSanaeiNameFieldState(showSanaei);
        if (remoteUsernameGroup) {
            remoteUsernameGroup.hidden = showSanaei || autoPpp;
        }
        if (remoteUsernameInput) {
            remoteUsernameInput.disabled = showSanaei || autoPpp;
            if (showSanaei || autoPpp) {
                remoteUsernameInput.removeAttribute('required');
                remoteUsernameInput.value = '';
            }
        }
        if (autoUsernameHint) {
            autoUsernameHint.hidden = showSanaei;
            if (showSanaei) {
                autoUsernameHint.textContent = '';
            } else if (autoPpp) {
                autoUsernameHint.textContent = @json(__('accounts.ppp_auto_credentials_hint'));
            } else {
                autoUsernameHint.textContent = @json(__('accounts.auto_username_hint'));
            }
        }
    }

    let optionsById = Object.fromEntries(packageOptions.map(function (p) { return [String(p.id), p]; }));
    const serverCatalog = serverSelect
        ? @json($servers->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values())
        : [];
    let previewRequestId = 0;
    let packageRequestId = 0;

    function rebuildPackageSelect(groups) {
        const current = packageSelect.value;
        packageSelect.innerHTML = '<option value="">—</option>';
        (groups || []).forEach(function (group) {
            const optgroup = document.createElement('optgroup');
            optgroup.label = group.label || '';
            (group.packages || []).forEach(function (pkg) {
                const opt = document.createElement('option');
                opt.value = pkg.id;
                opt.textContent = pkg.name;
                if (String(pkg.id) === String(current)) {
                    opt.selected = true;
                }
                optgroup.appendChild(opt);
            });
            packageSelect.appendChild(optgroup);
        });
    }

    function loadPackageOptions() {
        const params = new URLSearchParams();
        if (ownerSelect && ownerSelect.value) {
            params.set('owner_seller_id', ownerSelect.value);
        }

        const requestId = ++packageRequestId;
        fetch(packageOptionsUrl + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.json().then(function (payload) {
                    return { ok: response.ok, payload: payload };
                });
            })
            .then(function (result) {
                if (requestId !== packageRequestId) return;
                if (!result.ok) return;
                packageOptions = result.payload.packageOptions || [];
                optionsById = Object.fromEntries(packageOptions.map(function (p) { return [String(p.id), p]; }));
                rebuildPackageSelect(result.payload.packageGroups || []);
                refresh();
            })
            .catch(function () {});
    }

    function extractApiError(payload, fallback) {
        if (!payload || typeof payload !== 'object') return fallback;
        if (payload.error) return payload.error;
        if (payload.message) return payload.message;
        if (payload.errors && typeof payload.errors === 'object') {
            const first = Object.values(payload.errors)[0];
            if (Array.isArray(first) && first[0]) return first[0];
        }
        return fallback;
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

    function currencyMetaFrom(source) {
        if (!source) {
            return { symbol: @json(__('packages.toman')), label: @json(__('packages.toman')), decimals: 0 };
        }
        return {
            code: source.currency || 'IRT',
            symbol: source.currency_symbol || source.currency_label || @json(__('packages.toman')),
            label: source.currency_label || source.currency_symbol || @json(__('packages.toman')),
            decimals: Number.isFinite(Number(source.currency_decimals)) ? Number(source.currency_decimals) : 0,
        };
    }

    function formatToman(value) {
        return formatMoney(value, { label: @json(__('packages.toman')), symbol: @json(__('packages.toman')), decimals: 0 });
    }

    function hidePricing() {
        if (!pricingBox) return;
        pricingBox.hidden = true;
        if (pricingError) pricingError.hidden = true;
    }

    function showPricingError(message) {
        if (!pricingBox || !pricingError) return;
        pricingBox.hidden = false;
        pricingError.hidden = false;
        pricingError.textContent = message;
        pricingList.textContent = '—';
        pricingFinal.textContent = '—';
        if (pricingDiscountHint) pricingDiscountHint.textContent = '';
        if (pricingMarginHint) pricingMarginHint.textContent = '';
    }

    function renderPricing(data) {
        if (!pricingBox) return;
        pricingBox.hidden = false;
        if (pricingError) pricingError.hidden = true;
        const currencyMeta = currencyMetaFrom(data);
        let listText = formatMoney(data.wholesale_price || data.list_price, currencyMeta);
        if (data.is_elastic && data.data_gb && data.unit_price) {
            listText = Number(data.data_gb).toLocaleString('fa-IR') + ' {{ __('packages.gb_unit') }} × '
                + formatMoney(data.unit_price, currencyMeta) + ' = '
                + listText;
        }
        pricingList.textContent = listText;
        pricingFinal.textContent = formatMoney(data.final_charge, currencyMeta);
        if (pricingDiscountHint) {
            pricingDiscountHint.textContent = '';
            if (data.plan_applied && Number(data.plan_discount) > 0) {
                pricingDiscountHint.textContent = @json(__('ui.financial_plan_discount')) + ': ' + formatMoney(data.plan_discount, currencyMeta);
            } else if (data.discount_active && Number(data.list_price) !== Number(data.charged_price)) {
                pricingDiscountHint.textContent = pricingLabels.discount_hint.replace(':percent', String(data.discount_percent));
            }
        }
        if (pricingMarginHint && pricingLabels.margin_hint) {
            pricingMarginHint.textContent = '';
            if (Number(data.agent_margin) > 0) {
                let hint = pricingLabels.margin_hint.replace(':amount', formatMoney(data.agent_margin, currencyMeta));
                if (data.agent_margin_percent) {
                    hint = hint.replace(':percent', String(data.agent_margin_percent));
                } else {
                    hint = hint.replace(@json(__('ui.agent_margin_hint_percent_part')), '');
                }
                pricingMarginHint.textContent = hint;
            }
        }
    }

    function loadPricingPreview() {
        const durationId = durationSelect.value;
        const pkg = optionsById[String(packageSelect.value)] || null;
        if (!durationId) {
            hidePricing();
            return;
        }

        const params = new URLSearchParams({ package_duration_id: durationId });
        if (ownerSelect && ownerSelect.value) {
            params.set('owner_seller_id', ownerSelect.value);
        }
        if (pkg && pkg.is_elastic && gbInput) {
            const minGb = pkg.min_gb || 1;
            const gbVal = gbInput.value && Number(gbInput.value) > 0 ? gbInput.value : String(minGb);
            params.set('data_gb', gbVal);
        }

        const requestId = ++previewRequestId;
        fetch(purchasePreviewUrl + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.json().then(function (payload) {
                    return { ok: response.ok, payload: payload };
                }).catch(function () {
                    return { ok: false, payload: {} };
                });
            })
            .then(function (result) {
                if (requestId !== previewRequestId) return;
                if (!result.ok) {
                    showPricingError(extractApiError(result.payload, pricingLabels.preview_failed));
                    return;
                }
                renderPricing(result.payload);
            })
            .catch(function () {
                if (requestId !== previewRequestId) return;
                showPricingError(pricingLabels.preview_failed);
            });
    }

    function refresh() {
        const pkg = optionsById[String(packageSelect.value)] || null;

        durationSelect.innerHTML = '<option value="">—</option>';
        if (serverSelect) {
            serverSelect.innerHTML = '<option value="">' + @json(__('ui.server_auto_option')) + '</option>';
        }
        hidePricing();

        if (gbGroup) {
            gbGroup.hidden = !(pkg && pkg.is_elastic);
            if (pkg && pkg.is_elastic && gbInput) {
                const min = pkg.min_gb || 1;
                const max = pkg.max_gb;
                gbInput.min = min;
                if (max) { gbInput.max = max; } else { gbInput.removeAttribute('max'); }
                const oldGb = @json(old('data_gb'));
                if (!gbInput.value || Number(gbInput.value) < min) {
                    gbInput.value = oldGb || min;
                }
                if (gbHint) {
                    gbHint.textContent = max
                        ? '{{ __('packages.gb_range_hint') }}'.replace(':min', min).replace(':max', max)
                        : '{{ __('packages.gb_min_hint') }}'.replace(':min', min);
                }
            }
        }

        toggleSanaeiClientName(pkg);

        if (!pkg) return;

        pkg.durations.forEach(function (d) {
            const opt = document.createElement('option');
            opt.value = d.id;
            const currencyMeta = currencyMetaFrom(pkg);
            const priceLabel = pkg.is_elastic
                ? formatMoney(d.price, currencyMeta) + ' / {{ __('packages.gb_unit') }}'
                : formatMoney(d.price, currencyMeta);
            opt.textContent = d.label + ' — ' + priceLabel + (d.is_test ? ' ({{ __('packages.test_badge') }})' : '');
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

    packageSelect.addEventListener('change', refresh);
    durationSelect.addEventListener('change', loadPricingPreview);
    if (gbInput) {
        let gbTimer = null;
        gbInput.addEventListener('input', function () {
            clearTimeout(gbTimer);
            gbTimer = setTimeout(loadPricingPreview, 350);
        });
    }
    if (ownerSelect) {
        ownerSelect.addEventListener('change', function () {
            loadPackageOptions();
            loadPricingPreview();
        });
    }
    refresh();
})();
</script>
@endpush
@endif
