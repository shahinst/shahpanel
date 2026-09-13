@extends('layouts.panel')

@section('page_title', __('broadcasts.send_new'))

@section('panel_content')
@php
    $sellerScope = old('seller_scope', 'all');
    $oldSellerIds = collect(old('seller_ids', []))->map(fn ($id) => (int) $id)->all();
    $oldAgentIds = collect(old('agent_ids', []))->map(fn ($id) => (int) $id)->all();

    if ($oldAgentIds === [] && $oldSellerIds !== []) {
        $oldAgentIds = $agents
            ->filter(fn ($agent) => $agent->children->contains(fn ($seller) => in_array((int) $seller->id, $oldSellerIds, true)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    $agentOptions = $agents->map(fn ($agent) => [
        'id' => (int) $agent->id,
        'name' => $agent->full_name ?: $agent->username,
        'sellers' => $agent->children->map(fn ($seller) => [
            'id' => (int) $seller->id,
            'name' => $seller->full_name ?: $seller->username,
        ])->values()->all(),
    ])->values()->all();
@endphp

<x-card>
    <form method="POST" action="{{ route('admin.broadcasts.store') }}" enctype="multipart/form-data" id="broadcast-form">
        @csrf
        <div class="row">
            <x-form.group wide :label="__('broadcasts.audience')">
                <select name="audience" id="broadcast-audience" required class="form-control">
                    <option value="agents" @selected(old('audience') === 'agents')>{{ __('broadcasts.to_agents') }}</option>
                    <option value="sellers" @selected(old('audience', 'sellers') === 'sellers')>{{ __('broadcasts.audience_sellers') }}</option>
                </select>
            </x-form.group>

            <div id="broadcast-seller-scope" class="col-12 mb-3" @if(old('audience', 'sellers') !== 'sellers') hidden @endif>
                <label class="form-label d-block">{{ __('broadcasts.seller_scope') }}</label>
                <div class="row g-2">
                    <div class="col-sm-6">
                        <input type="radio" class="btn-check" name="seller_scope" id="seller-scope-all" value="all" @checked($sellerScope === 'all') autocomplete="off">
                        <label class="btn btn-outline-primary w-100 py-2" for="seller-scope-all">{{ __('broadcasts.seller_scope_all') }}</label>
                    </div>
                    <div class="col-sm-6">
                        <input type="radio" class="btn-check" name="seller_scope" id="seller-scope-selected" value="selected" @checked($sellerScope === 'selected') autocomplete="off">
                        <label class="btn btn-outline-primary w-100 py-2" for="seller-scope-selected">{{ __('broadcasts.seller_scope_selected') }}</label>
                    </div>
                </div>
            </div>

            <div id="broadcast-seller-picker" class="col-12 mb-3" @if(old('audience', 'sellers') !== 'sellers' || $sellerScope !== 'selected') hidden @endif>
                <div class="card border">
                    <div class="card-body">
                        <h6 class="card-title mb-2">{{ __('broadcasts.pick_agents') }}</h6>
                        <p class="text-muted small mb-3">{{ __('broadcasts.pick_agents_hint') }}</p>
                        <div class="row g-2" id="broadcast-agent-list">
                            @forelse ($agents as $agent)
                                <div class="col-md-6 col-lg-4">
                                    <label class="d-flex align-items-center gap-2 border rounded px-3 py-2 mb-0 h-100">
                                        <input
                                            type="checkbox"
                                            class="form-check-input mt-0 broadcast-agent-checkbox"
                                            name="agent_ids[]"
                                            value="{{ $agent->id }}"
                                            data-agent-id="{{ $agent->id }}"
                                            @checked(in_array((int) $agent->id, $oldAgentIds, true))
                                        >
                                        <span>{{ $agent->full_name ?: $agent->username }}</span>
                                    </label>
                                </div>
                            @empty
                                <p class="text-muted mb-0">{{ __('broadcasts.no_agents') }}</p>
                            @endforelse
                        </div>

                        <div id="broadcast-seller-groups" class="mt-4"></div>
                    </div>
                </div>
            </div>

            <x-form.group wide :label="__('broadcasts.title')">
                <input name="title" value="{{ old('title') }}" required class="form-control">
            </x-form.group>
            <x-form.group wide :label="__('broadcasts.body')">
                <textarea name="body" rows="4" required class="form-control">{{ old('body') }}</textarea>
            </x-form.group>
            <x-form.group wide :label="__('broadcasts.link')">
                <input name="link" value="{{ old('link') }}" class="form-control">
            </x-form.group>
            <x-form.group wide :label="__('broadcasts.image')">
                <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="form-control">
                <small class="text-muted d-block mt-1">{{ __('broadcasts.image_hint') }}</small>
            </x-form.group>
            <x-form.actions>
                <x-button type="submit">{{ __('broadcasts.send_now') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</x-card>

@push('scripts')
<script>
(function () {
    const agents = @json($agentOptions);
    const oldSellerIds = @json($oldSellerIds);
    const audienceSelect = document.getElementById('broadcast-audience');
    const sellerScopeWrap = document.getElementById('broadcast-seller-scope');
    const sellerPicker = document.getElementById('broadcast-seller-picker');
    const sellerGroups = document.getElementById('broadcast-seller-groups');
    const scopeAll = document.getElementById('seller-scope-all');
    const scopeSelected = document.getElementById('seller-scope-selected');
    const form = document.getElementById('broadcast-form');

    if (!audienceSelect || !sellerGroups) return;

    const agentsById = Object.fromEntries(agents.map(function (agent) {
        return [String(agent.id), agent];
    }));

    function sellerScopeValue() {
        return scopeSelected && scopeSelected.checked ? 'selected' : 'all';
    }

    function selectedAgentIds() {
        return Array.from(document.querySelectorAll('.broadcast-agent-checkbox:checked'))
            .map(function (input) { return String(input.value); });
    }

    function currentlyCheckedSellerIds() {
        return Array.from(sellerGroups.querySelectorAll('.broadcast-seller-checkbox:checked'))
            .map(function (box) { return parseInt(box.value, 10); })
            .filter(function (id) { return !isNaN(id); });
    }

    function renderSellerGroups() {
        const checkedIds = currentlyCheckedSellerIds();
        const selectionIds = checkedIds.length > 0 ? checkedIds : oldSellerIds;

        sellerGroups.innerHTML = '';
        const ids = selectedAgentIds();

        if (ids.length === 0) {
            sellerGroups.innerHTML = '<p class="text-muted small mb-0">' + @json(__('broadcasts.select_agent_first')) + '</p>';
            return;
        }

        ids.forEach(function (agentId) {
            const agent = agentsById[agentId];
            if (!agent) {
                return;
            }

            if (!agent.sellers || agent.sellers.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'border rounded p-3 mb-3 text-muted small';
                empty.textContent = agent.name + ' — ' + @json(__('broadcasts.no_sellers_for_agent'));
                sellerGroups.appendChild(empty);
                return;
            }

            const group = document.createElement('div');
            group.className = 'broadcast-seller-group border rounded p-3 mb-3';
            group.dataset.agentId = agentId;

            const head = document.createElement('div');
            head.className = 'd-flex flex-wrap justify-content-between align-items-center gap-2 mb-2';
            head.innerHTML =
                '<strong>' + agent.name + '</strong>' +
                '<label class="small mb-0 d-inline-flex align-items-center gap-1">' +
                    '<input type="checkbox" class="form-check-input mt-0 broadcast-select-all-sellers" data-agent-id="' + agentId + '">' +
                    @json(__('broadcasts.select_all_sellers')) +
                '</label>';

            const list = document.createElement('div');
            list.className = 'row g-2';

            agent.sellers.forEach(function (seller) {
                const col = document.createElement('div');
                col.className = 'col-md-6 col-lg-4';
                const checked = selectionIds.indexOf(seller.id) !== -1 ? ' checked' : '';
                col.innerHTML =
                    '<label class="d-flex align-items-center gap-2 border rounded px-3 py-2 mb-0 h-100">' +
                        '<input type="checkbox" class="form-check-input mt-0 broadcast-seller-checkbox" name="seller_ids[]" value="' + seller.id + '" data-agent-id="' + agentId + '"' + checked + '>' +
                        '<span>' + seller.name + '</span>' +
                    '</label>';
                list.appendChild(col);
            });

            group.appendChild(head);
            group.appendChild(list);
            sellerGroups.appendChild(group);

            syncSelectAllForAgent(agentId);
        });
    }

    function syncSelectAllForAgent(agentId) {
        const boxes = sellerGroups.querySelectorAll('.broadcast-seller-checkbox[data-agent-id="' + agentId + '"]');
        const selectAll = sellerGroups.querySelector('.broadcast-select-all-sellers[data-agent-id="' + agentId + '"]');
        if (!selectAll || boxes.length === 0) return;
        selectAll.checked = Array.from(boxes).every(function (box) { return box.checked; });
        selectAll.indeterminate = !selectAll.checked && Array.from(boxes).some(function (box) { return box.checked; });
    }

    function toggleSellerUi() {
        const isSellers = audienceSelect.value === 'sellers';
        if (sellerScopeWrap) sellerScopeWrap.hidden = !isSellers;
        if (sellerPicker) {
            sellerPicker.hidden = !isSellers || sellerScopeValue() !== 'selected';
        }
        if (isSellers && sellerScopeValue() === 'selected') {
            renderSellerGroups();
        }
    }

    audienceSelect.addEventListener('change', toggleSellerUi);
    if (scopeAll) scopeAll.addEventListener('change', toggleSellerUi);
    if (scopeSelected) scopeSelected.addEventListener('change', toggleSellerUi);

    document.addEventListener('change', function (event) {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;

        if (target.classList.contains('broadcast-agent-checkbox')) {
            renderSellerGroups();
            return;
        }

        if (target.classList.contains('broadcast-select-all-sellers')) {
            const agentId = target.getAttribute('data-agent-id');
            const checked = target.checked;
            sellerGroups.querySelectorAll('.broadcast-seller-checkbox[data-agent-id="' + agentId + '"]').forEach(function (box) {
                box.checked = checked;
            });
            target.indeterminate = false;
            return;
        }

        if (target.classList.contains('broadcast-seller-checkbox')) {
            syncSelectAllForAgent(target.getAttribute('data-agent-id'));
        }
    });

    if (form) {
        form.addEventListener('submit', function (event) {
            if (audienceSelect.value !== 'sellers' || sellerScopeValue() !== 'selected') {
                return;
            }

            const checkedSellers = form.querySelectorAll('input[name="seller_ids[]"]:checked');
            if (checkedSellers.length === 0) {
                event.preventDefault();
                window.alert(@json(__('broadcasts.sellers_required')));
            }
        });
    }

    toggleSellerUi();
})();
</script>
@endpush
@endsection
