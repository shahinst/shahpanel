@php
    $package = $package ?? null;
    $categories = $categories ?? collect();
    $durationsByTier = $durationsByTier ?? collect();

    $selectedServerIds = array_map(
        'intval',
        (array) old('server_ids', $package?->servers->pluck('id')->all() ?? [])
    );
    $serviceType = old('service_type', $package?->service_type?->value ?? \App\Enums\ServiceType::Wireguard->value);
    $currentServiceType = \App\Enums\ServiceType::from($serviceType);
    $pricingModel = old('pricing_model', $package?->pricing_model?->value ?? \App\Enums\PackagePricingModel::Fixed->value);
    $isPasarguardPackage = $currentServiceType->isPasarguard();
    $isRemnawavePackage = $currentServiceType->isRemnawave();
    $isCiscoAnyconnectPackage = $currentServiceType->isCiscoAnyconnect();
    $isMikrotikPackage = $currentServiceType->isMikrotik();

    $remnawaveTrafficStrategy = old('remnawave_traffic_strategy', $package?->remnawaveTrafficStrategy() ?? 'NO_RESET');

    $mikrotikServers = $servers->filter(fn ($s) => $s->isMikrotik());
    $selectedMikrotikServer = $mikrotikServers->first(
        fn ($s) => in_array((int) $s->id, $selectedServerIds, true)
    );
    if ($selectedMikrotikServer === null && $isMikrotikPackage && $mikrotikServers->count() === 1) {
        $selectedMikrotikServer = $mikrotikServers->first();
        if ($selectedServerIds === []) {
            $selectedServerIds = [(int) $selectedMikrotikServer->id];
        }
    }

    if (! isset($mikrotikProfilesByServer) || ! is_array($mikrotikProfilesByServer)) {
        $mikrotikProfilesByServer = [];
        foreach ($mikrotikServers as $mkServer) {
            $mikrotikProfilesByServer[(string) $mkServer->id] = [];
        }
    }

    $mikrotikProfileOptions = $selectedMikrotikServer
        ? ($mikrotikProfilesByServer[(string) $selectedMikrotikServer->id] ?? [])
        : [];

    $selectedMikrotikProfileKeys = array_map(
        'strval',
        (array) old('mikrotik_profile_keys', $package?->mikrotikProfileKeys() ?? [])
    );

    $pasarguardServers = $servers->filter(
        fn ($s) => $s->type === \App\Enums\ServerType::Pasarguard
    );
    $selectedPasarguardServer = $pasarguardServers->first(
        fn ($s) => in_array((int) $s->id, $selectedServerIds, true)
    );
    if ($selectedPasarguardServer === null && $isPasarguardPackage && $pasarguardServers->count() === 1) {
        $selectedPasarguardServer = $pasarguardServers->first();
        if ($selectedServerIds === []) {
            $selectedServerIds = [(int) $selectedPasarguardServer->id];
        }
    }

    $pasarguardGroupOptions = $selectedPasarguardServer
        ? \App\Services\Pasarguard\PasarguardGroupCatalog::forServer($selectedPasarguardServer)
        : ($pasarguardGroupOptions ?? []);

    if (! isset($pasarguardGroupsByServer) || ! is_array($pasarguardGroupsByServer)) {
        $pasarguardGroupsByServer = [];
        foreach ($pasarguardServers as $pgServer) {
            $pasarguardGroupsByServer[(string) $pgServer->id] = \App\Services\Pasarguard\PasarguardGroupCatalog::forServer($pgServer);
        }
    }
@endphp

<x-form.group label="نام">
    <input name="name" value="{{ old('name', $package?->name) }}" required class="form-control">
</x-form.group>
@php
    $selectedCurrency = old('currency', $package?->moneyCurrency()?->value ?? \App\Enums\MoneyCurrency::IRT->value);
    $currencyEnum = \App\Enums\MoneyCurrency::normalize($selectedCurrency);
@endphp
<x-form.group :label="__('packages.currency')">
    <select name="currency" id="package-currency" class="form-control" required @disabled($package !== null)>
        @foreach (\App\Enums\MoneyCurrency::sellable() as $currencyOption)
            <option value="{{ $currencyOption->value }}"
                    data-symbol="{{ $currencyOption->symbol() }}"
                    @selected($selectedCurrency === $currencyOption->value)>
                {{ $currencyOption->label() }} ({{ $currencyOption->symbol() }})
            </option>
        @endforeach
    </select>
    @if ($package !== null)
        <input type="hidden" name="currency" value="{{ $selectedCurrency }}">
        <p class="help-block text-muted mb-0">{{ __('packages.currency_locked_hint') }}</p>
    @else
        <p class="help-block text-muted mb-0">{{ __('packages.currency_hint') }}</p>
    @endif
</x-form.group>
<x-form.group :label="__('packages.category')">
    @if (($categories ?? collect())->isNotEmpty())
        <select name="package_category_id" class="form-control">
            <option value="">— {{ __('packages.uncategorized') }} —</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((int) old('package_category_id', $package?->package_category_id) === $category->id)>
                    {{ $category->name }}
                </option>
            @endforeach
        </select>
        <p class="help-block text-muted small mb-0">
            {{ __('packages.category_admin_only_hint') }}
            @if (\Illuminate\Support\Facades\Route::has('admin.package-categories.create'))
                — <a href="{{ route('admin.package-categories.create') }}">{{ __('packages.category_create') }}</a>
            @endif
        </p>
    @else
        <p class="text-muted small mb-0">{{ __('packages.uncategorized') }}</p>
    @endif
</x-form.group>
<x-form.group label="نوع سرویس">
    <select name="service_type" id="package-service-type" required class="form-control">
        <optgroup label="{{ __('packages.service_type_mikrotik') }}">
            @foreach (\App\Enums\ServiceType::cases() as $type)
                @continue($type->isSanaei() || $type->isPasarguard() || $type->isRemnawave() || $type->isCiscoAnyconnect())
                <option value="{{ $type->value }}" @selected($serviceType === $type->value)>{{ $type->label() }}</option>
            @endforeach
        </optgroup>
        <optgroup label="{{ __('packages.service_type_sanaei') }}">
            @foreach (\App\Enums\ServiceType::cases() as $type)
                @continue(! $type->isSanaei())
                <option value="{{ $type->value }}" @selected($serviceType === $type->value)>{{ $type->label() }}</option>
            @endforeach
        </optgroup>
        <optgroup label="{{ __('packages.service_type_pasarguard') }}">
            <option value="{{ \App\Enums\ServiceType::Pasarguard->value }}" @selected($serviceType === \App\Enums\ServiceType::Pasarguard->value)>
                {{ __('packages.service_type_pasarguard') }}
            </option>
        </optgroup>
        <optgroup label="{{ __('packages.service_type_remnawave') }}">
            <option value="{{ \App\Enums\ServiceType::Remnawave->value }}" @selected($serviceType === \App\Enums\ServiceType::Remnawave->value)>
                {{ __('packages.service_type_remnawave') }}
            </option>
        </optgroup>
        <optgroup label="{{ __('packages.service_type_cisco_anyconnect') }}">
            <option value="{{ \App\Enums\ServiceType::CiscoAnyconnect->value }}" @selected($serviceType === \App\Enums\ServiceType::CiscoAnyconnect->value)>
                {{ __('packages.service_type_cisco_anyconnect') }}
            </option>
        </optgroup>
    </select>
</x-form.group>

<div class="form-group col-md-12">
    <label>{{ __('packages.servers') }}</label>
    <p class="help-block">{{ __('packages.servers_hint') }}</p>
    <div class="row" id="package-server-list">
        @foreach ($servers as $server)
            @php
                $compatible = $server->isCompatibleWithServiceType($currentServiceType);
            @endphp
            @php
                $pgGroupsForRow = $server->isPasarguard()
                    ? \App\Services\Pasarguard\PasarguardGroupCatalog::forServer($server)
                    : [];
                $groupCount = count($pgGroupsForRow);
                $mkProfilesForRow = $server->isMikrotik()
                    ? ($mikrotikProfilesByServer[(string) $server->id] ?? [])
                    : [];
            @endphp
            <div class="col-md-4 col-sm-6 package-server-option {{ $compatible ? '' : 'd-none' }}"
                 data-server-type="{{ $server->type->value }}"
                 data-server-id="{{ $server->id }}"
                 @if($server->isPasarguard()) data-pasarguard-groups='@json($pgGroupsForRow)' @endif
                 @if($server->isMikrotik()) data-mikrotik-profiles='@json($mkProfilesForRow)' @endif>
                <label class="checkbox-inline" style="display:block;padding:8px 12px;border:1px solid #ddd;border-radius:4px;margin-bottom:8px;">
                    <input type="checkbox" name="server_ids[]" value="{{ $server->id }}"
                           class="package-server-checkbox"
                           @checked(
                               in_array((int) $server->id, $selectedServerIds, true)
                               || ($isPasarguardPackage && $pasarguardServers->count() === 1 && (int) $server->id === (int) $pasarguardServers->first()->id)
                           )
                           {{ $compatible ? '' : 'disabled' }}>
                    {{ $server->name }}
                    <small class="text-muted">({{ $server->type->value }}@if($groupCount > 0) — {{ persian_digits($groupCount) }} {{ __('servers.pasarguard_groups') }}@endif)</small>
                </label>
            </div>
        @endforeach
    </div>
</div>

<div id="package-mikrotik-fields" class="col-12 mb-3 {{ $isMikrotikPackage ? '' : 'd-none' }}">
    <div class="panel-form-section border rounded p-3 bg-light">
        <h4 class="panel-form-section-title h6 mb-3"><i class="bx bx-server align-middle"></i> {{ __('packages.mikrotik_section_title') }}</h4>
        <p class="help-block text-info small">{{ __('packages.mikrotik_help') }}</p>
        <p class="help-block text-muted small mb-2">{{ __('packages.mikrotik_multi_select_hint') }}</p>
        <div class="row">
            <div class="col-md-8">
                <x-form.group :label="__('packages.mikrotik_profile')">
                    @php
                        $mkKindForView = $currentServiceType === \App\Enums\ServiceType::Wireguard ? 'wg' : 'ppp';
                        $selectedMikrotikServers = $mikrotikServers->filter(
                            fn ($s) => in_array((int) $s->id, $selectedServerIds, true)
                        );
                    @endphp
                    {{-- One group per selected MikroTik server: the server name, then its own interfaces. --}}
                    <div id="mikrotik-profile-groups" class="border rounded p-2 bg-white"
                         data-initial='@json($selectedMikrotikProfileKeys)'>
                        @forelse ($selectedMikrotikServers as $mkServer)
                            @php
                                $rowsForServer = array_values(array_filter(
                                    $mikrotikProfilesByServer[(string) $mkServer->id] ?? [],
                                    fn ($p) => ($p['kind'] ?? null) === $mkKindForView
                                ));
                            @endphp
                            <div class="mikrotik-profile-server-group mb-3" data-server-id="{{ $mkServer->id }}">
                                <div class="fw-bold small text-primary border-bottom pb-1 mb-2">
                                    <i class="bx bx-server align-middle"></i> {{ $mkServer->name }}
                                </div>
                                @forelse ($rowsForServer as $profile)
                                    <label class="d-block mb-2 ps-2">
                                        <input type="checkbox" name="mikrotik_profile_keys[]" value="{{ $profile['key'] }}"
                                               class="mikrotik-profile-checkbox"
                                               @checked(in_array($profile['key'], $selectedMikrotikProfileKeys, true))>
                                        {{ $profile['label'] }}
                                    </label>
                                @empty
                                    <p class="text-muted small mb-0 ps-2">{{ __('packages.mikrotik_profiles_empty') }}</p>
                                @endforelse
                            </div>
                        @empty
                            <p class="text-muted small mb-0">{{ __('packages.mikrotik_profiles_select_server') }}</p>
                        @endforelse
                    </div>
                    {{-- Master list of every server's interfaces; the JS rebuilds the groups above from this. --}}
                    <div id="mikrotik-profile-options-template" class="d-none" aria-hidden="true">
                        @foreach ($mikrotikServers as $mkServer)
                            @foreach ($mikrotikProfilesByServer[(string) $mkServer->id] ?? [] as $profile)
                                <label class="d-block mb-2 mikrotik-profile-option"
                                       data-server-id="{{ $mkServer->id }}"
                                       data-server-name="{{ $mkServer->name }}"
                                       data-kind="{{ $profile['kind'] }}">
                                    <input type="checkbox" name="mikrotik_profile_keys[]" value="{{ $profile['key'] }}"
                                           class="mikrotik-profile-checkbox">
                                    {{ $profile['label'] }}
                                </label>
                            @endforeach
                        @endforeach
                    </div>
                    <small class="text-muted d-block mt-1" id="mikrotik-profile-hint">
                        {{ __('packages.mikrotik_multi_select_hint') }}
                    </small>
                </x-form.group>
            </div>
        </div>
    </div>
</div>

<div id="package-pasarguard-fields" class="col-12 mb-3 {{ $isPasarguardPackage ? '' : 'd-none' }}">
    <div class="panel-form-section border rounded p-3 bg-light">
        <h4 class="panel-form-section-title h6 mb-3"><i class="bx bx-group align-middle"></i> {{ __('packages.pasarguard_section_title') }}</h4>
        <p class="help-block text-info small">{{ __('packages.pasarguard_help') }}</p>
        <p class="help-block text-muted small mb-2">{{ __('packages.pasarguard_unlimited_duration_hint') }}</p>
        <div class="row">
            <div class="col-md-6">
                <x-form.group :label="__('packages.pasarguard_group')">
                    <select name="pasarguard_group_id" id="pasarguard-group-select" class="form-control"
                            data-initial="{{ old('pasarguard_group_id', $package?->pasarguard_group_id) }}">
                        <option value="">{{ __('packages.pasarguard_choose_group') }}</option>
                        @foreach ($pasarguardGroupOptions as $group)
                            <option value="{{ $group['id'] }}" @selected((int) old('pasarguard_group_id', $package?->pasarguard_group_id) === (int) $group['id'])>
                                {{ $group['name'] }} (#{{ persian_digits($group['id']) }})
                            </option>
                        @endforeach
                    </select>
                    <select id="pasarguard-group-options-template" class="d-none" aria-hidden="true" tabindex="-1">
                        <option value="">{{ __('packages.pasarguard_choose_group') }}</option>
                        @foreach ($pasarguardServers as $pgServer)
                            @foreach ($pasarguardGroupsByServer[(string) $pgServer->id] ?? [] as $group)
                                <option value="{{ $group['id'] }}"
                                        data-server-id="{{ $pgServer->id }}"
                                        data-label="{{ $group['name'] }} (#{{ persian_digits($group['id']) }})">
                                    {{ $group['name'] }} (#{{ persian_digits($group['id']) }})
                                </option>
                            @endforeach
                        @endforeach
                    </select>
                    <small class="text-muted d-block mt-1" id="pasarguard-group-hint">
                        @if ($isPasarguardPackage && $selectedPasarguardServer && $pasarguardGroupOptions === [])
                            {{ __('packages.pasarguard_groups_not_synced') }}
                        @elseif ($isPasarguardPackage && ! $selectedPasarguardServer)
                            {{ __('packages.pasarguard_groups_select_server') }}
                        @else
                            {{ __('packages.pasarguard_groups_from_server_cache') }}
                        @endif
                    </small>
                </x-form.group>
            </div>
            <div class="col-md-6">
                <x-form.group :label="__('packages.pasarguard_hwid_limit')" :hint="__('packages.pasarguard_hwid_limit_hint')">
                    <input name="pasarguard_hwid_limit" type="number" min="0" max="255" class="form-control"
                           value="{{ old('pasarguard_hwid_limit', $package?->pasarguard_hwid_limit ?? 0) }}">
                </x-form.group>
            </div>
            <div class="col-md-12">
                <x-form.group :label="__('packages.pasarguard_expiry_activation')">
                    <select name="pasarguard_expiry_activation" class="form-control">
                        @foreach (\App\Enums\PasarguardExpiryActivation::cases() as $activation)
                            <option value="{{ $activation->value }}" @selected(old('pasarguard_expiry_activation', $package?->pasarguard_expiry_activation?->value ?? \App\Enums\PasarguardExpiryActivation::FromCreation->value) === $activation->value)>
                                {{ $activation->label() }}
                            </option>
                        @endforeach
                    </select>
                </x-form.group>
            </div>
        </div>
    </div>
</div>

<div id="package-remnawave-fields" class="col-12 mb-3 {{ $isRemnawavePackage ? '' : 'd-none' }}">
    <div class="panel-form-section border rounded p-3 bg-light">
        <h4 class="panel-form-section-title h6 mb-3"><i class="bx bx-group align-middle"></i> {{ __('packages.remnawave_section_title') }}</h4>
        <p class="help-block text-info small">{{ __('packages.remnawave_help') }}</p>
        <div class="row">
            <div class="col-md-6">
                <x-form.group :label="__('packages.remnawave_traffic_strategy')">
                    <select name="remnawave_traffic_strategy" class="form-control">
                        @foreach (['NO_RESET', 'DAY', 'WEEK', 'MONTH'] as $strategy)
                            <option value="{{ $strategy }}" @selected($remnawaveTrafficStrategy === $strategy)>
                                @switch($strategy)
                                    @case('DAY') {{ __('packages.remnawave_traffic_day') }} @break
                                    @case('WEEK') {{ __('packages.remnawave_traffic_week') }} @break
                                    @case('MONTH') {{ __('packages.remnawave_traffic_month') }} @break
                                    @default {{ __('packages.remnawave_traffic_no_reset') }}
                                @endswitch
                            </option>
                        @endforeach
                    </select>
                </x-form.group>
            </div>
        </div>
    </div>
</div>

<x-form.group :label="__('packages.pricing_model')" :hint="__('packages.pricing_model_hint')">
    <select name="pricing_model" id="package-pricing-model" class="form-control">
        @foreach (\App\Enums\PackagePricingModel::cases() as $model)
            <option value="{{ $model->value }}" @selected($pricingModel === $model->value)>{{ $model->label() }}</option>
        @endforeach
    </select>
</x-form.group>

<div id="package-fixed-fields" @if($pricingModel === \App\Enums\PackagePricingModel::Elastic->value) hidden @endif>
    <x-form.group label="حجم (GB)">
        <input name="data_limit_gb" type="number" step="0.01" value="{{ old('data_limit_gb', $package?->data_limit_gb) }}" class="form-control">
    </x-form.group>
</div>

<div id="package-elastic-fields" class="row" @if($pricingModel !== \App\Enums\PackagePricingModel::Elastic->value) hidden @endif>
    <div class="col-12">
        <p class="help-block text-info">{{ __('packages.elastic_help') }}</p>
        <p class="help-block text-muted small mb-0">{{ __('packages.elastic_unlimited_price_hint') }}</p>
    </div>
    <x-form.group :label="__('packages.min_data_gb')">
        <input name="min_data_gb" type="number" step="0.01" min="1" value="{{ old('min_data_gb', $package?->min_data_gb) }}" class="form-control">
    </x-form.group>
    <x-form.group :label="__('packages.max_data_gb')">
        <input name="max_data_gb" type="number" step="0.01" min="1" value="{{ old('max_data_gb', $package?->max_data_gb) }}" class="form-control">
    </x-form.group>
</div>

<x-form.group label="ترتیب">
    <input name="sort_order" type="number" value="{{ old('sort_order', $package?->sort_order ?? 0) }}" class="form-control">
</x-form.group>

<div class="form-group col-md-12">
    <label>{{ __('packages.commercial_durations') }}</label>
    <p class="help-block text-muted small mb-1" id="package-price-hint"
       data-fixed="{{ __('packages.price_hint_fixed') }}"
       data-elastic="{{ __('packages.price_hint_elastic') }}">
        {{ $pricingModel === \App\Enums\PackagePricingModel::Elastic->value ? __('packages.price_hint_elastic') : __('packages.price_hint_fixed') }}
    </p>
    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>{{ __('packages.duration') }}</th>
                    <th id="package-price-col"
                        class="js-package-price-label"
                        data-mode="commercial"
                        data-fixed="{{ __('packages.price_amount', ['currency' => $currencyEnum->label()]) }}"
                        data-elastic="{{ __('packages.price_per_gb_amount', ['currency' => $currencyEnum->label()]) }}">
                        {{ $pricingModel === \App\Enums\PackagePricingModel::Elastic->value
                            ? __('packages.price_per_gb_amount', ['currency' => $currencyEnum->label()])
                            : __('packages.price_amount', ['currency' => $currencyEnum->label()]) }}
                    </th>
                    <th>{{ __('packages.enabled') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach (\App\Enums\PackageDurationTier::commercial() as $tier)
                    @php
                        $row = old("durations.{$tier->value}", [
                            'price' => $durationsByTier->get($tier->value)?->price ?? '',
                            'is_enabled' => $durationsByTier->get($tier->value)?->is_enabled ?? false,
                        ]);
                    @endphp
                    <tr>
                        <td>{{ $tier->label() }}</td>
                        <td>
                            <input type="number" step="0.01" min="0" name="durations[{{ $tier->value }}][price]"
                                   value="{{ $row['price'] }}" class="form-control input-sm">
                        </td>
                        <td class="text-center">
                            <input type="checkbox" name="durations[{{ $tier->value }}][is_enabled]" value="1"
                                   @checked(filter_var($row['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN))>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="form-group col-md-12">
    <label>{{ __('packages.test_durations') }}</label>
    <p class="help-block">{{ __('packages.test_durations_hint') }}</p>
    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>{{ __('packages.duration') }}</th>
                    <th id="package-test-price-col" class="js-package-price-label" data-mode="test">
                        {{ __('packages.price_amount', ['currency' => $currencyEnum->label()]) }}
                    </th>
                    <th>{{ __('packages.enabled') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach (\App\Enums\PackageDurationTier::test() as $tier)
                    @php
                        $row = old("durations.{$tier->value}", [
                            'price' => $durationsByTier->get($tier->value)?->price ?? 0,
                            'is_enabled' => $durationsByTier->get($tier->value)?->is_enabled ?? false,
                        ]);
                    @endphp
                    <tr>
                        <td>{{ $tier->label() }}</td>
                        <td>
                            <input type="number" step="0.01" min="0" name="durations[{{ $tier->value }}][price]"
                                   value="{{ $row['price'] }}" class="form-control input-sm">
                        </td>
                        <td class="text-center">
                            <input type="checkbox" name="durations[{{ $tier->value }}][is_enabled]" value="1"
                                   @checked(filter_var($row['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN))>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>


<div id="package-cisco-anyconnect-fields" class="col-12 mb-3 {{ $isCiscoAnyconnectPackage ? '' : 'd-none' }}">
    <div class="panel-form-section">
        <h4 class="panel-form-section-title">Cisco AnyConnect</h4>
        <p class="text-muted small">{{ __('packages.cisco_anyconnect_fields_hint') }}</p>
        <div class="row">
            <div class="col-md-4">
                <x-form.group :label="__('packages.cisco_group_policy')">
                    <input name="cisco_group_policy" value="{{ old('cisco_group_policy', $package?->cisco_group_policy) }}" class="form-control" dir="ltr" placeholder="{{ __('packages.cisco_inherit_from_server') }}">
                </x-form.group>
            </div>
            <div class="col-md-4">
                <x-form.group :label="__('packages.cisco_tunnel_group')">
                    <input name="cisco_tunnel_group" value="{{ old('cisco_tunnel_group', $package?->cisco_tunnel_group) }}" class="form-control" dir="ltr" placeholder="{{ __('packages.cisco_inherit_from_server') }}">
                </x-form.group>
            </div>
            <div class="col-md-4">
                <x-form.group :label="__('packages.cisco_simultaneous_logins')">
                    <input name="cisco_simultaneous_logins" type="number" min="0" max="100" value="{{ old('cisco_simultaneous_logins', $package?->cisco_simultaneous_logins) }}" class="form-control" placeholder="{{ __('packages.cisco_inherit_from_server') }}">
                </x-form.group>
            </div>
        </div>
    </div>
</div>

<x-form.checkbox name="is_active" :label="__('packages.package_active')" :checked="old('is_active', $package?->is_active ?? true)" :hiddenZero="true" />
<x-form.checkbox name="kyc_required" :label="__('kyc.package_required')" :hint="__('kyc.package_required_hint')" :checked="old('kyc_required', $package?->kyc_required ?? false)" :hiddenZero="true" />

<script>
document.addEventListener('DOMContentLoaded', function () {
    const serviceSelect = document.getElementById('package-service-type');
    if (!serviceSelect) return;

    const pasarguardType = @json(\App\Enums\ServiceType::Pasarguard->value);
    const remnawaveType = @json(\App\Enums\ServiceType::Remnawave->value);
    const ciscoAnyconnectType = @json(\App\Enums\ServiceType::CiscoAnyconnect->value);
    const sanaeiTypes = @json(array_map(
        fn ($t) => $t->value,
        array_filter(\App\Enums\ServiceType::cases(), fn ($t) => $t->isSanaei())
    ));
    const pasarguardGroupsByServer = @json($pasarguardGroupsByServer);
    const mikrotikProfilesByServer = @json($mikrotikProfilesByServer);
    const wireguardServiceType = @json(\App\Enums\ServiceType::Wireguard->value);

    function refreshServers() {
        const value = serviceSelect.value;
        const isPasarguard = value === pasarguardType;
        const isRemnawave = value === remnawaveType;
        const isSanaei = sanaeiTypes.includes(value);
        document.querySelectorAll('.package-server-option').forEach(function (el) {
            const type = el.dataset.serverType;
            const isCisco = value === ciscoAnyconnectType;
            const compatible = isPasarguard
                ? type === 'pasarguard'
                : (isRemnawave ? type === 'remnawave' : (isCisco ? type === 'cisco_anyconnect' : (isSanaei ? type === 'sanaei' : type === 'mikrotik')));
            const input = el.querySelector('input[type=checkbox]');
            el.classList.toggle('d-none', !compatible);
            if (!input) {
                return;
            }
            input.disabled = !compatible;
            if (!compatible) {
                input.checked = false;
            }
        });
        refreshPasarguardFields();
        refreshRemnawaveFields();
        refreshMikrotikFields();
    }

    const mikrotikFields = document.getElementById('package-mikrotik-fields');
    const mikrotikProfileGroups = document.getElementById('mikrotik-profile-groups');
    const mikrotikProfileHint = document.getElementById('mikrotik-profile-hint');
    const mikrotikServerNames = @json($mikrotikServers->pluck('name', 'id'));
    // Remembered interface selection, kept across server toggles so switching the
    // set of selected servers never silently drops a chosen interface.
    let mikrotikSelectedKeys = null;

    function mikrotikEscape(text) {
        const div = document.createElement('div');
        div.textContent = String(text == null ? '' : text);
        return div.innerHTML;
    }

    // Live provisioning-options fetch: when a server is ticked, pull its
    // interfaces / groups from the remote right now, so a freshly-added server's
    // options show up without saving, leaving to sync, and coming back.
    const serverOptionsUrlTemplate = @json(route('admin.packages.server-options', ['server' => '__ID__'], false));
    const serverOptionsInFlight = {};

    function fetchServerProvisioningOptions(serverId, kind) {
        const idStr = String(parseInt(serverId, 10));
        if (!idStr || idStr === 'NaN' || serverOptionsInFlight[idStr]) {
            return;
        }
        serverOptionsInFlight[idStr] = true;

        const hintEl = kind === 'pasarguard' ? groupHint : mikrotikProfileHint;
        const prevHint = hintEl ? hintEl.textContent : '';
        if (hintEl) {
            hintEl.textContent = @json(__('packages.loading_from_server'));
        }

        const url = serverOptionsUrlTemplate.replace('__ID__', encodeURIComponent(idStr)) + '?sync=1';

        fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (data) {
                // Keep both the JS maps and the per-row data-* attributes in sync,
                // because the lookup helpers read the row attribute first.
                const row = document.querySelector('.package-server-option[data-server-id="' + idStr + '"]');
                if (Array.isArray(data.mikrotik_profiles)) {
                    mikrotikProfilesByServer[idStr] = data.mikrotik_profiles;
                    if (row) { row.dataset.mikrotikProfiles = JSON.stringify(data.mikrotik_profiles); }
                }
                if (Array.isArray(data.pasarguard_groups)) {
                    pasarguardGroupsByServer[idStr] = data.pasarguard_groups;
                    if (row) { row.dataset.pasarguardGroups = JSON.stringify(data.pasarguard_groups); }
                }
                if (kind === 'pasarguard') {
                    loadPasarguardGroupsForSelectedServer();
                } else {
                    loadMikrotikProfilesForSelectedServer();
                }
                if (hintEl) {
                    hintEl.textContent = (data.sync_ok === false && data.sync_message)
                        ? @json(__('packages.load_from_server_failed')) + ' ' + data.sync_message
                        : prevHint;
                }
            })
            .catch(function () {
                if (hintEl) {
                    hintEl.textContent = @json(__('packages.load_from_server_failed'));
                }
            })
            .finally(function () {
                serverOptionsInFlight[idStr] = false;
            });
    }

    const pasarguardFields = document.getElementById('package-pasarguard-fields');
    const remnawaveFields = document.getElementById('package-remnawave-fields');
    const ciscoFields = document.getElementById('package-cisco-anyconnect-fields');
    const groupSelect = document.getElementById('pasarguard-group-select');
    const groupHint = document.getElementById('pasarguard-group-hint');

    function refreshRemnawaveFields() {
        if (!remnawaveFields) return;
        const isRemnawave = serviceSelect.value === remnawaveType;
        const isCisco = serviceSelect.value === ciscoAnyconnectType;
        if (remnawaveFields) remnawaveFields.classList.toggle('d-none', !isRemnawave);
        if (ciscoFields) ciscoFields.classList.toggle('d-none', !isCisco);
        if (!isRemnawave) {
            return;
        }
        autoSelectSingleRemnawaveServer();
    }

    function autoSelectSingleRemnawaveServer() {
        if (serviceSelect.value !== remnawaveType) return;
        const visible = Array.from(document.querySelectorAll('.package-server-option[data-server-type="remnawave"]:not(.d-none) input.package-server-checkbox'));
        const checked = visible.filter(function (i) { return i.checked; });
        if (checked.length === 0 && visible.length === 1) {
            visible[0].checked = true;
        }
    }

    function refreshMikrotikFields() {
        if (!mikrotikFields) return;
        const mikrotikTypes = @json(array_map(fn ($t) => $t->value, array_filter(
            \App\Enums\ServiceType::cases(),
            fn ($t) => $t->isMikrotik()
        )));
        const isMikrotik = mikrotikTypes.includes(serviceSelect.value);
        mikrotikFields.classList.toggle('d-none', !isMikrotik);
        if (!isMikrotik) {
            return;
        }
        autoSelectSingleMikrotikServer();
        loadMikrotikProfilesForSelectedServer();
    }

    function autoSelectSingleMikrotikServer() {
        const mikrotikTypes = @json(array_map(fn ($t) => $t->value, array_filter(
            \App\Enums\ServiceType::cases(),
            fn ($t) => $t->isMikrotik()
        )));
        if (!mikrotikTypes.includes(serviceSelect.value)) return;
        const visible = Array.from(document.querySelectorAll('.package-server-option[data-server-type="mikrotik"]:not(.d-none) input.package-server-checkbox'));
        const checked = visible.filter(function (i) { return i.checked; });
        if (checked.length === 0 && visible.length === 1) {
            visible[0].checked = true;
            loadMikrotikProfilesForSelectedServer();
        }
    }

    function collectCheckedMikrotikKeys(into) {
        if (!mikrotikProfileGroups) return into;
        mikrotikProfileGroups.querySelectorAll('input.mikrotik-profile-checkbox:checked').forEach(function (i) {
            into[i.value] = true;
        });
        return into;
    }

    // Render one group per selected MikroTik server: the server's name, then its
    // own interfaces. Balancing later picks the emptier server, so every selected
    // server needs its own interface chosen here.
    function loadMikrotikProfilesForSelectedServer() {
        if (!mikrotikProfileGroups) return;

        // Seed the remembered selection once from the server-rendered initial
        // list, then fold in whatever is currently checked before we re-render.
        if (mikrotikSelectedKeys === null) {
            mikrotikSelectedKeys = {};
            try {
                (JSON.parse(mikrotikProfileGroups.dataset.initial || '[]') || []).forEach(function (k) {
                    mikrotikSelectedKeys[k] = true;
                });
            } catch (e) { /* ignore */ }
        }
        collectCheckedMikrotikKeys(mikrotikSelectedKeys);

        const kindFilter = serviceSelect.value === wireguardServiceType ? 'wg' : 'ppp';
        const checkedServers = Array.from(document.querySelectorAll(
            '.package-server-option[data-server-type="mikrotik"] input.package-server-checkbox:checked'
        )).map(function (i) { return String(parseInt(i.value, 10)); });

        if (!checkedServers.length) {
            mikrotikProfileGroups.innerHTML = '<p class="text-muted small mb-0">'
                + @json(__('packages.mikrotik_profiles_select_server')) + '</p>';
            return;
        }

        let html = '';
        checkedServers.forEach(function (serverId) {
            const name = mikrotikServerNames[serverId] || mikrotikServerNames[Number(serverId)] || ('#' + serverId);
            const list = mikrotikProfilesByServer[serverId] || mikrotikProfilesByServer[String(serverId)] || [];
            const profiles = list.filter(function (p) { return p.kind === kindFilter; });

            html += '<div class="mikrotik-profile-server-group mb-3" data-server-id="' + serverId + '">';
            html += '<div class="fw-bold small text-primary border-bottom pb-1 mb-2">'
                + '<i class="bx bx-server align-middle"></i> ' + mikrotikEscape(name) + '</div>';
            if (profiles.length) {
                profiles.forEach(function (p) {
                    const key = p.key;
                    const label = p.label || p.name || key;
                    const isChecked = mikrotikSelectedKeys[key] ? ' checked' : '';
                    html += '<label class="d-block mb-2 ps-2"><input type="checkbox" name="mikrotik_profile_keys[]" value="'
                        + mikrotikEscape(key) + '" class="mikrotik-profile-checkbox"' + isChecked + '> '
                        + mikrotikEscape(label) + '</label>';
                });
            } else {
                html += '<p class="text-muted small mb-0 ps-2">' + @json(__('packages.mikrotik_profiles_empty')) + '</p>';
            }
            html += '</div>';
        });
        mikrotikProfileGroups.innerHTML = html;
        mikrotikProfileGroups.dataset.initial = '[]';

        // Keep the remembered set in sync as the admin toggles interfaces.
        mikrotikProfileGroups.querySelectorAll('input.mikrotik-profile-checkbox').forEach(function (cb) {
            cb.addEventListener('change', function () {
                if (cb.checked) { mikrotikSelectedKeys[cb.value] = true; }
                else { delete mikrotikSelectedKeys[cb.value]; }
            });
        });
    }

    function refreshPasarguardFields() {
        if (!pasarguardFields) return;
        const isPasarguard = serviceSelect.value === pasarguardType;
        pasarguardFields.classList.toggle('d-none', !isPasarguard);
        if (groupSelect) {
            groupSelect.required = isPasarguard;
        }
        if (!isPasarguard) {
            return;
        }
        autoSelectSinglePasarguardServer();
        loadPasarguardGroupsForSelectedServer();
    }

    function groupsForServerId(serverId) {
        if (!serverId) {
            return [];
        }
        const row = document.querySelector('.package-server-option[data-server-id="' + serverId + '"]');
        if (row && row.dataset.pasarguardGroups) {
            try {
                const parsed = JSON.parse(row.dataset.pasarguardGroups);
                if (Array.isArray(parsed) && parsed.length) {
                    return parsed;
                }
            } catch (e) { /* ignore */ }
        }
        return pasarguardGroupsByServer[serverId]
            || pasarguardGroupsByServer[String(serverId)]
            || [];
    }

    function groupsFromTemplate(serverId) {
        const template = document.getElementById('pasarguard-group-options-template');
        if (!template || !serverId) {
            return [];
        }
        const out = [];
        template.querySelectorAll('option[data-server-id]').forEach(function (opt) {
            if (String(opt.dataset.serverId) !== String(serverId)) {
                return;
            }
            const id = parseInt(opt.value, 10);
            if (!id) {
                return;
            }
            out.push({
                id: id,
                name: opt.dataset.label || opt.textContent.trim(),
            });
        });
        return out;
    }

    function autoSelectSinglePasarguardServer() {
        if (serviceSelect.value !== pasarguardType) return;
        const visible = Array.from(document.querySelectorAll('.package-server-option[data-server-type="pasarguard"]:not(.d-none) input.package-server-checkbox'));
        const checked = visible.filter(function (i) { return i.checked; });
        if (checked.length === 0 && visible.length === 1) {
            visible[0].checked = true;
            loadPasarguardGroupsForSelectedServer();
        }
    }

    function loadPasarguardGroupsForSelectedServer() {
        if (!groupSelect) return;
        const checked = document.querySelector('.package-server-option[data-server-type="pasarguard"] input[type=checkbox]:checked');
        const serverId = checked ? String(parseInt(checked.value, 10)) : '';
        const initial = groupSelect.dataset.initial || '';
        const chooseLabel = @json(__('packages.pasarguard_choose_group'));

        if (!serverId) {
            groupSelect.innerHTML = '<option value="">' + chooseLabel + '</option>';
            if (groupHint) {
                groupHint.textContent = @json(__('packages.pasarguard_groups_select_server'));
            }
            return;
        }

        let groups = groupsForServerId(serverId);
        if (!groups.length) {
            groups = groupsFromTemplate(serverId);
        }
        if (!groups.length) {
            const fromMap = pasarguardGroupsByServer[serverId] || pasarguardGroupsByServer[String(serverId)];
            if (Array.isArray(fromMap)) {
                groups = fromMap;
            }
        }

        let html = '<option value="">' + chooseLabel + '</option>';
        groups.forEach(function (g) {
            const id = g.id;
            const label = g.name || ('#' + id);
            const sel = String(initial) === String(id) ? ' selected' : '';
            html += '<option value="' + id + '"' + sel + '>' + label + '</option>';
        });
        groupSelect.innerHTML = html;
        groupSelect.dataset.initial = '';

        if (groupHint) {
            groupHint.textContent = groups.length
                ? @json(__('packages.pasarguard_groups_cached_count')).replace(':count', String(groups.length))
                : @json(__('packages.pasarguard_groups_not_synced'));
        }
    }

    serviceSelect.addEventListener('change', refreshServers);
    refreshServers();
    document.querySelectorAll('.package-server-checkbox').forEach(function (el) {
        el.addEventListener('change', function () {
            if (serviceSelect.value === pasarguardType && el.checked) {
                document.querySelectorAll('.package-server-option[data-server-type="pasarguard"] .package-server-checkbox').forEach(function (other) {
                    if (other !== el) {
                        other.checked = false;
                    }
                });
            }
            if (serviceSelect.value === pasarguardType) {
                loadPasarguardGroupsForSelectedServer();
                if (el.checked) {
                    fetchServerProvisioningOptions(el.value, 'pasarguard');
                }
            }
            if (serviceSelect.value === remnawaveType && el.checked) {
                document.querySelectorAll('.package-server-option[data-server-type="remnawave"] .package-server-checkbox').forEach(function (other) {
                    if (other !== el) {
                        other.checked = false;
                    }
                });
            }
            const mikrotikTypes = @json(array_map(fn ($t) => $t->value, array_filter(
                \App\Enums\ServiceType::cases(),
                fn ($t) => $t->isMikrotik()
            )));
            if (mikrotikTypes.includes(serviceSelect.value)) {
                loadMikrotikProfilesForSelectedServer();
                if (el.checked) {
                    fetchServerProvisioningOptions(el.value, 'mikrotik');
                }
            }
        });
    });

    const pricingSelect = document.getElementById('package-pricing-model');
    const fixedFields = document.getElementById('package-fixed-fields');
    const elasticFields = document.getElementById('package-elastic-fields');
    const priceHint = document.getElementById('package-price-hint');

    function refreshPricingModel() {
        if (!pricingSelect) return;
        const elastic = pricingSelect.value === 'elastic';
        if (fixedFields) fixedFields.hidden = elastic;
        if (elasticFields) elasticFields.hidden = !elastic;
        if (priceHint) priceHint.textContent = elastic ? priceHint.dataset.elastic : priceHint.dataset.fixed;
        if (typeof window.refreshPackageCurrencyLabels === 'function') {
            window.refreshPackageCurrencyLabels();
        }
    }

    if (pricingSelect) {
        pricingSelect.addEventListener('change', refreshPricingModel);
        refreshPricingModel();
    }
});
</script>

{{-- Isolated from the big package-form script so a server-option JS error cannot block currency updates. --}}
@php
    $packageCurrencyMeta = [];
    foreach (\App\Enums\MoneyCurrency::sellable() as $currencyOption) {
        $display = $currencyOption->label();
        $packageCurrencyMeta[$currencyOption->value] = [
            'symbol' => $currencyOption->symbol(),
            'label' => $currencyOption->label(),
            'fixed' => __('packages.price_amount', ['currency' => $display]),
            'elastic' => __('packages.price_per_gb_amount', ['currency' => $display]),
        ];
    }
@endphp
<script>
(function () {
    const currencyMeta = @json($packageCurrencyMeta);

    function currentCurrencyCode() {
        const select = document.getElementById('package-currency');
        if (!select) {
            return 'IRT';
        }
        return select.value || 'IRT';
    }

    function currentPricingIsElastic() {
        const pricingSelect = document.getElementById('package-pricing-model');
        return pricingSelect && pricingSelect.value === 'elastic';
    }

    function refreshPackageCurrencyLabels() {
        const code = currentCurrencyCode();
        const meta = currencyMeta[code] || currencyMeta.IRT;
        if (!meta) {
            return;
        }

        const commercial = document.getElementById('package-price-col');
        if (commercial) {
            commercial.dataset.fixed = meta.fixed;
            commercial.dataset.elastic = meta.elastic;
            commercial.textContent = currentPricingIsElastic() ? meta.elastic : meta.fixed;
        }

        const testCol = document.getElementById('package-test-price-col');
        if (testCol) {
            testCol.textContent = meta.fixed;
        }

        document.querySelectorAll('.js-package-price-label').forEach(function (el) {
            if (el.id === 'package-price-col' || el.id === 'package-test-price-col') {
                return;
            }
            const mode = el.getAttribute('data-mode');
            el.textContent = (mode === 'elastic' || (mode === 'commercial' && currentPricingIsElastic()))
                ? meta.elastic
                : meta.fixed;
        });
    }

    window.refreshPackageCurrencyLabels = refreshPackageCurrencyLabels;

    function bindCurrencySelect() {
        const select = document.getElementById('package-currency');
        if (!select || select.dataset.currencyBound === '1') {
            return;
        }
        select.dataset.currencyBound = '1';
        ['change', 'input'].forEach(function (evt) {
            select.addEventListener(evt, refreshPackageCurrencyLabels);
        });
        refreshPackageCurrencyLabels();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindCurrencySelect);
    } else {
        bindCurrencySelect();
    }
})();
</script>
