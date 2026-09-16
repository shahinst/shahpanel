@extends('layouts.panel')

@section('page_title', __('gift_accounts.title'))

@section('panel_content')
<x-card>
    <p class="text-muted">{{ __('gift_accounts.subtitle') }}</p>

    @if (session('gift_skipped'))
        <x-alert type="info" class="mb-3">
            <strong>{{ __('gift_accounts.skipped_title') }}</strong>
            <ul class="mb-0 mt-2 small">
                @foreach (session('gift_skipped') as $row)
                    <li>{{ $row['message'] ?? (($row['name'] ?? '—').': '.($row['existing_label'] ?? '')) }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    @if (session('gift_failures'))
        <x-alert type="warning" class="mb-3">
            <strong>{{ __('gift_accounts.failures_title') }}</strong>
            <ul class="mb-0 mt-2 small">
                @foreach (session('gift_failures') as $failure)
                    <li>{{ $failure['name'] ?? '—' }}: {{ $failure['error'] ?? '' }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    <form method="POST" action="{{ route('admin.gift-accounts.store') }}" id="gift-account-form">
        @csrf

        <div class="row">
            <div class="col-lg-6 mb-4">
                <h5 class="border-bottom pb-2">{{ __('gift_accounts.agents_section') }}</h5>
                <p class="text-muted small">{{ __('gift_accounts.agents_hint') }}</p>
                <div class="border rounded p-3" style="max-height: 280px; overflow-y: auto;">
                    @forelse ($agents as $agent)
                        <div class="form-check">
                            <input class="form-check-input gift-agent-checkbox" type="checkbox"
                                   name="agent_ids[]" value="{{ $agent->id }}" id="gift-agent-{{ $agent->id }}"
                                   @checked(collect(old('agent_ids', []))->contains((string) $agent->id) || collect(old('agent_ids', []))->contains($agent->id))>
                            <label class="form-check-label" for="gift-agent-{{ $agent->id }}">
                                {{ $agent->full_name }}
                                <span class="text-muted small">({{ $agent->username }})</span>
                            </label>
                        </div>
                    @empty
                        <p class="text-muted small mb-0">—</p>
                    @endforelse
                </div>
            </div>

            <div class="col-lg-6 mb-4">
                <h5 class="border-bottom pb-2">{{ __('gift_accounts.sellers_section') }}</h5>
                <p class="text-muted small">{{ __('gift_accounts.sellers_hint') }}</p>
                <div id="gift-sellers-placeholder" class="text-muted small">{{ __('gift_accounts.select_agents_first') }}</div>
                <div class="border rounded p-3 d-none" id="gift-sellers-panel" style="max-height: 280px; overflow-y: auto;">
                    <div id="gift-sellers-list"></div>
                </div>
            </div>
        </div>

        <h5 class="border-bottom pb-2 mt-2">{{ __('gift_accounts.package_section') }}</h5>
        <div class="row">
            <x-form.group :label="__('gift_accounts.package')" class="col-md-6">
                <select name="package_id" id="gift-package-id" required class="form-control">
                    <option value="">—</option>
                    @foreach ($packageGroups as $group)
                        @if (! empty($group['label']))
                            <optgroup label="{{ $group['label'] }}">
                                @foreach ($group['packages'] as $package)
                                    <option value="{{ $package->id }}"
                                            data-elastic="{{ $package->isElastic() ? '1' : '0' }}"
                                            data-min-gb="{{ $package->min_data_gb ?? '' }}"
                                            data-max-gb="{{ $package->max_data_gb ?? '' }}"
                                            @selected(old('package_id') == $package->id)>
                                        {{ $package->name }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @else
                            @foreach ($group['packages'] as $package)
                                <option value="{{ $package->id }}"
                                        data-elastic="{{ $package->isElastic() ? '1' : '0' }}"
                                        data-min-gb="{{ $package->min_data_gb ?? '' }}"
                                        data-max-gb="{{ $package->max_data_gb ?? '' }}"
                                        @selected(old('package_id') == $package->id)>
                                    {{ $package->name }}
                                </option>
                            @endforeach
                        @endif
                    @endforeach
                </select>
            </x-form.group>

            <x-form.group :label="__('gift_accounts.duration')" class="col-md-6">
                <select name="package_duration_id" id="gift-duration-id" required class="form-control">
                    <option value="">—</option>
                </select>
            </x-form.group>

            <div class="col-md-6 mb-3 d-none" id="gift-data-gb-group">
                <label class="form-label">{{ __('gift_accounts.data_gb') }}</label>
                <input type="number" name="data_gb" id="gift-data-gb" class="form-control" min="1" step="1"
                       value="{{ old('data_gb') }}">
                <p class="form-text text-muted small mb-0" id="gift-data-gb-hint"></p>
            </div>

            <x-form.group :label="__('gift_accounts.charge')" :hint="__('gift_accounts.charge_hint')" class="col-md-6">
                <input type="number" name="charge" class="form-control" min="0" step="1" required
                       value="{{ old('charge', '0') }}">
            </x-form.group>

            <x-form.group :label="__('gift_accounts.server')" class="col-md-6">
                <select name="server_id" class="form-control">
                    <option value="">{{ __('gift_accounts.server_auto') }}</option>
                    @foreach ($servers as $server)
                        <option value="{{ $server->id }}" @selected(old('server_id') == $server->id)>{{ $server->name }}</option>
                    @endforeach
                </select>
            </x-form.group>

            <x-form.group :label="__('gift_accounts.name_prefix')" :hint="__('gift_accounts.name_prefix_hint')" class="col-md-6">
                <input type="text" name="name_prefix" id="gift-name-prefix" class="form-control" maxlength="200" required
                       value="{{ old('name_prefix', __('ui.gift_name_prefix_default')) }}">
                <p class="form-text text-muted small mb-0 mt-1">
                    {{ __('gift_accounts.name_preview') }}: <span id="gift-name-preview">—</span>
                </p>
            </x-form.group>
        </div>

        <h5 class="border-bottom pb-2 mt-3">{{ __('gift_accounts.expiry_section') }}</h5>
        <div class="row">
            <div class="col-12 mb-3">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="expiry_unlimited" id="gift-expiry-unlimited" value="1"
                           @checked(old('expiry_unlimited'))>
                    <label class="form-check-label" for="gift-expiry-unlimited">{{ __('gift_accounts.expiry_unlimited') }}</label>
                </div>
            </div>
            <x-form.group :label="__('gift_accounts.expiry_jalali')" class="col-md-4">
                <x-form.jalali-date name="expiry_jalali" :value="old('expiry_jalali')" />
            </x-form.group>
        </div>

        <x-form.actions>
            <x-button type="submit">{{ __('gift_accounts.create_submit') }}</x-button>
        </x-form.actions>
    </form>
</x-card>
@endsection

@push('scripts')
<script>
(function () {
    var sellersUrl = @json(route('admin.gift-accounts.sellers'));
    var packageDurations = @json($packageDurations);
    var oldSellerIds = @json(collect(old('seller_ids', []))->map(fn ($id) => (int) $id)->values());
    var oldPackageId = @json(old('package_id'));
    var oldDurationId = @json(old('package_duration_id'));

    var agentCheckboxes = document.querySelectorAll('.gift-agent-checkbox');
    var sellersPanel = document.getElementById('gift-sellers-panel');
    var sellersList = document.getElementById('gift-sellers-list');
    var sellersPlaceholder = document.getElementById('gift-sellers-placeholder');
    var packageSelect = document.getElementById('gift-package-id');
    var durationSelect = document.getElementById('gift-duration-id');
    var dataGbGroup = document.getElementById('gift-data-gb-group');
    var dataGbInput = document.getElementById('gift-data-gb');
    var dataGbHint = document.getElementById('gift-data-gb-hint');
    var namePrefix = document.getElementById('gift-name-prefix');
    var namePreview = document.getElementById('gift-name-preview');

    function selectedAgentIds() {
        return Array.from(agentCheckboxes).filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
    }

    function loadSellers() {
        var ids = selectedAgentIds();
        if (ids.length === 0) {
            sellersPanel.classList.add('d-none');
            sellersPlaceholder.classList.remove('d-none');
            sellersList.innerHTML = '';
            return;
        }

        var params = new URLSearchParams();
        ids.forEach(function (id) { params.append('agent_ids[]', id); });

        fetch(sellersUrl + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var sellers = data.sellers || [];
                if (sellers.length === 0) {
                    sellersPanel.classList.add('d-none');
                    sellersPlaceholder.textContent = @json(__('gift_accounts.no_sellers'));
                    sellersPlaceholder.classList.remove('d-none');
                    return;
                }

                sellersPlaceholder.classList.add('d-none');
                sellersPanel.classList.remove('d-none');
                sellersList.innerHTML = sellers.map(function (seller) {
                    var checked = oldSellerIds.indexOf(seller.id) !== -1 ? ' checked' : '';
                    return '<div class="form-check">' +
                        '<input class="form-check-input gift-seller-checkbox" type="checkbox" name="seller_ids[]" value="' + seller.id + '" id="gift-seller-' + seller.id + '"' + checked + '>' +
                        '<label class="form-check-label" for="gift-seller-' + seller.id + '">' + seller.full_name +
                        ' <span class="text-muted small">(' + seller.username + ')</span></label></div>';
                }).join('');

                bindSellerPreview();
            });
    }

    function syncDurations() {
        var packageId = packageSelect.value;
        durationSelect.innerHTML = '<option value="">—</option>';
        if (!packageId || !packageDurations[packageId]) return;

        packageDurations[packageId].forEach(function (row) {
            var opt = document.createElement('option');
            opt.value = row.id;
            opt.textContent = row.label;
            if (String(oldDurationId) === String(row.id)) opt.selected = true;
            durationSelect.appendChild(opt);
        });
    }

    function syncElastic() {
        var opt = packageSelect.options[packageSelect.selectedIndex];
        var elastic = opt && opt.getAttribute('data-elastic') === '1';
        dataGbGroup.classList.toggle('d-none', !elastic);
        if (elastic && opt) {
            var min = opt.getAttribute('data-min-gb') || '';
            var max = opt.getAttribute('data-max-gb') || '';
            dataGbHint.textContent = min && max ? @json(__('ui.between_gb_range')).replace(':min', min).replace(':max', max) : '';
            if (min) dataGbInput.min = min;
            if (max) dataGbInput.max = max;
        }
    }

    function bindSellerPreview() {
        document.querySelectorAll('.gift-seller-checkbox, .gift-agent-checkbox').forEach(function (el) {
            el.removeEventListener('change', updateNamePreview);
            el.addEventListener('change', updateNamePreview);
        });
        updateNamePreview();
    }

    function updateNamePreview() {
        var prefix = (namePrefix && namePrefix.value) ? namePrefix.value : '';
        var first = document.querySelector('.gift-seller-checkbox:checked') ||
            document.querySelector('.gift-agent-checkbox:checked');
        if (!first) {
            namePreview.textContent = prefix ? prefix + '…' : '—';
            return;
        }
        var label = document.querySelector('label[for="' + first.id + '"]');
        namePreview.textContent = prefix + (label ? label.textContent.trim().split('(')[0].trim() : '…');
    }

    agentCheckboxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
            loadSellers();
            updateNamePreview();
        });
    });

    if (packageSelect) {
        packageSelect.addEventListener('change', function () {
            syncDurations();
            syncElastic();
        });
    }

    if (namePrefix) {
        namePrefix.addEventListener('input', updateNamePreview);
    }

    if (oldPackageId) {
        packageSelect.value = oldPackageId;
    }
    syncDurations();
    syncElastic();
    loadSellers();
    updateNamePreview();
})();
</script>
@endpush
