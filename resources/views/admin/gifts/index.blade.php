@extends('layouts.panel')

@section('page_title', __('gifts.title'))

@section('panel_content')
@if (session('gift_failures'))
    <x-alert type="warning" class="mb-3">
        <strong>{{ __('gifts.failures_title') }}</strong>
        <ul class="mb-0 mt-2 small">
            @foreach (session('gift_failures') as $failure)
                <li>{{ is_array($failure) ? ($failure['error'] ?? json_encode($failure, JSON_UNESCAPED_UNICODE)) : $failure }}</li>
            @endforeach
        </ul>
    </x-alert>
@endif

<x-card class="mb-4">
    <h4 class="margin-top-none">{{ __('gifts.title') }}</h4>
    <p class="text-muted">{{ __('gifts.subtitle') }}</p>

    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2">
                <h5 class="mb-0">{{ __('gifts.agents_section') }}</h5>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" id="gift-select-all-agents">{{ __('gifts.select_all_agents') }}</button>
                    <button type="button" class="btn btn-outline-secondary" id="gift-clear-agents">{{ __('gifts.clear_agents') }}</button>
                </div>
            </div>
            <p class="text-muted small">{{ __('gifts.agents_hint') }}</p>
            <div class="border rounded p-3" style="max-height: 280px; overflow-y: auto;">
                @forelse ($agents as $agent)
                    <div class="form-check">
                        <input class="form-check-input gift-agent-checkbox" type="checkbox"
                               value="{{ $agent->id }}" id="gift-agent-{{ $agent->id }}"
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
            <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2">
                <h5 class="mb-0">{{ __('gifts.sellers_section') }}</h5>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" id="gift-select-all-sellers">{{ __('gifts.select_all_sellers') }}</button>
                    <button type="button" class="btn btn-outline-secondary" id="gift-clear-sellers">{{ __('gifts.clear_sellers') }}</button>
                </div>
            </div>
            <p class="text-muted small">{{ __('gifts.sellers_hint') }}</p>
            <div class="border rounded p-3" style="max-height: 280px; overflow-y: auto;" id="gift-sellers-panel">
                @forelse ($sellers as $seller)
                    <div class="form-check gift-seller-row" data-parent-id="{{ $seller->parent_id }}">
                        <input class="form-check-input gift-seller-checkbox" type="checkbox"
                               value="{{ $seller->id }}" id="gift-seller-{{ $seller->id }}"
                               @checked(collect(old('seller_ids', []))->contains((string) $seller->id) || collect(old('seller_ids', []))->contains($seller->id))>
                        <label class="form-check-label" for="gift-seller-{{ $seller->id }}">
                            {{ $seller->full_name }}
                            <span class="text-muted small">({{ $seller->username }})</span>
                        </label>
                    </div>
                @empty
                    <p class="text-muted small mb-0">{{ __('gifts.no_sellers') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</x-card>

<div class="row">
    <div class="col-lg-6 mb-4">
        <x-card>
            <h4 class="margin-top-none">{{ __('gifts.days_section') }}</h4>
            <p class="text-muted small">{{ __('gifts.days_hint') }}</p>

            <form method="POST" action="{{ route('admin.gifts.days') }}" id="gift-days-form">
                @csrf
                <div id="gift-days-recipients"></div>

                <x-form.group :label="__('gifts.days')">
                    <input type="number" name="days" class="form-control" min="1" max="3650" required
                           value="{{ old('days', 3) }}">
                </x-form.group>

                <x-form.group :label="__('gifts.note')">
                    <textarea name="note" rows="2" class="form-control" maxlength="500">{{ old('note') }}</textarea>
                </x-form.group>

                <x-form.actions>
                    <x-button type="submit">{{ __('gifts.days_submit') }}</x-button>
                </x-form.actions>
            </form>
        </x-card>
    </div>

    <div class="col-lg-6 mb-4">
        <x-card>
            <h4 class="margin-top-none">{{ __('gifts.wallet_section') }}</h4>
            <p class="text-muted small">{{ __('gifts.wallet_hint') }}</p>

            <form method="POST" action="{{ route('admin.gifts.wallet') }}" id="gift-wallet-form">
                @csrf
                <div id="gift-wallet-recipients"></div>

                <x-form.group :label="__('gifts.amount')">
                    <input type="number" name="amount" class="form-control" min="1" step="1" required
                           value="{{ old('amount') }}" placeholder="500000">
                </x-form.group>

                <x-form.group :label="__('gifts.note')">
                    <textarea name="note" rows="2" class="form-control" maxlength="500">{{ old('note') }}</textarea>
                </x-form.group>

                <x-form.actions>
                    <x-button type="submit">{{ __('gifts.wallet_submit') }}</x-button>
                </x-form.actions>
            </form>
        </x-card>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var confirmDays = @json(__('gifts.confirm_days'));
    var confirmWallet = @json(__('gifts.confirm_wallet'));

    var agentCheckboxes = document.querySelectorAll('.gift-agent-checkbox');
    var sellerRows = document.querySelectorAll('.gift-seller-row');
    var daysRecipients = document.getElementById('gift-days-recipients');
    var walletRecipients = document.getElementById('gift-wallet-recipients');
    var daysForm = document.getElementById('gift-days-form');
    var walletForm = document.getElementById('gift-wallet-form');

    function selectedAgentIds() {
        return Array.from(agentCheckboxes).filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
    }

    function selectedSellerIds() {
        return Array.from(document.querySelectorAll('.gift-seller-checkbox:checked')).map(function (cb) { return cb.value; });
    }

    function syncHiddenRecipients() {
        var agentsHtml = selectedAgentIds().map(function (id) {
            return '<input type="hidden" name="agent_ids[]" value="' + id + '">';
        }).join('');
        var sellersHtml = selectedSellerIds().map(function (id) {
            return '<input type="hidden" name="seller_ids[]" value="' + id + '">';
        }).join('');
        var html = agentsHtml + sellersHtml;
        if (daysRecipients) daysRecipients.innerHTML = html;
        if (walletRecipients) walletRecipients.innerHTML = html;
    }

    function filterSellers() {
        var agentIds = selectedAgentIds();
        sellerRows.forEach(function (row) {
            if (agentIds.length === 0) {
                row.classList.remove('d-none');
                return;
            }
            var parentId = String(row.getAttribute('data-parent-id') || '');
            var visible = agentIds.indexOf(parentId) !== -1;
            row.classList.toggle('d-none', !visible);
            if (!visible) {
                var cb = row.querySelector('.gift-seller-checkbox');
                if (cb) cb.checked = false;
            }
        });
        syncHiddenRecipients();
    }

    agentCheckboxes.forEach(function (cb) {
        cb.addEventListener('change', filterSellers);
    });
    document.querySelectorAll('.gift-seller-checkbox').forEach(function (cb) {
        cb.addEventListener('change', syncHiddenRecipients);
    });

    var selectAllAgents = document.getElementById('gift-select-all-agents');
    var clearAgents = document.getElementById('gift-clear-agents');
    var selectAllSellers = document.getElementById('gift-select-all-sellers');
    var clearSellers = document.getElementById('gift-clear-sellers');

    if (selectAllAgents) {
        selectAllAgents.addEventListener('click', function () {
            agentCheckboxes.forEach(function (cb) { cb.checked = true; });
            filterSellers();
        });
    }
    if (clearAgents) {
        clearAgents.addEventListener('click', function () {
            agentCheckboxes.forEach(function (cb) { cb.checked = false; });
            filterSellers();
        });
    }
    if (selectAllSellers) {
        selectAllSellers.addEventListener('click', function () {
            document.querySelectorAll('.gift-seller-row:not(.d-none) .gift-seller-checkbox').forEach(function (cb) {
                cb.checked = true;
            });
            syncHiddenRecipients();
        });
    }
    if (clearSellers) {
        clearSellers.addEventListener('click', function () {
            document.querySelectorAll('.gift-seller-checkbox').forEach(function (cb) { cb.checked = false; });
            syncHiddenRecipients();
        });
    }

    function guardSubmit(e, message) {
        syncHiddenRecipients();
        if (selectedAgentIds().length === 0 && selectedSellerIds().length === 0) {
            e.preventDefault();
            alert(@json(__('gifts.recipients_required')));
            return;
        }
        if (!confirm(message)) {
            e.preventDefault();
        }
    }

    if (daysForm) {
        daysForm.addEventListener('submit', function (e) { guardSubmit(e, confirmDays); });
    }
    if (walletForm) {
        walletForm.addEventListener('submit', function (e) { guardSubmit(e, confirmWallet); });
    }

    filterSellers();
})();
</script>
@endpush
