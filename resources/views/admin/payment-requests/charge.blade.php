@extends('layouts.panel')

@section('page_title', __('wallet.manual_charge'))

@section('panel_content')
<div class="row">
    <div class="col-md-6">
        <x-card>
            <h4 class="margin-top-none">{{ __('wallet.single_charge') }}</h4>
            <p class="help-block text-muted">{{ __('wallet.charge_form_record_hint') }}</p>
            <form method="POST" action="{{ route('admin.payment-requests.charge.store') }}" id="single-charge-form">
                @csrf
                <input type="hidden" name="charge_token" value="{{ $chargeToken ?? '' }}">
                <div class="row">
                    <x-form.group wide :label="__('wallet.target_user')">
                        <select name="user_id" id="charge-user-id" required class="form-control">
                            @foreach ($users as $user)
                                @php
                                    $walletMap = $user->wallets->mapWithKeys(fn ($w) => [(string) $w->currency => (string) $w->balance])->all();
                                    $enabled = $user->enabledCurrencyCodes();
                                @endphp
                                <option value="{{ $user->id }}"
                                        data-role="{{ $user->role->label() }}"
                                        data-enabled='@json($enabled)'
                                        data-balances='@json($walletMap)'
                                        @selected(old('user_id') == $user->id)>
                                    {{ $user->full_name }} ({{ $user->role->label() }})
                                </option>
                            @endforeach
                        </select>
                    </x-form.group>
                    <x-form.group wide :label="__('wallet.wallet_currency')">
                        <select name="currency" id="charge-currency" required class="form-control">
                            @foreach ($currencies as $currencyOption)
                                <option value="{{ $currencyOption->value }}"
                                        data-symbol="{{ $currencyOption->symbol() }}"
                                        @selected(old('currency', \App\Enums\MoneyCurrency::IRT->value) === $currencyOption->value)>
                                    {{ $currencyOption->label() }} ({{ $currencyOption->symbol() }})
                                </option>
                            @endforeach
                        </select>
                    </x-form.group>
                    <div class="col-12 mb-3">
                        <div class="alert alert-light border py-2 mb-0 small">
                            <strong>{{ __('wallet.balance_for_selected') }}:</strong>
                            <span id="charge-user-balance">{{ format_money('0', \App\Enums\MoneyCurrency::IRT) }}</span>
                        </div>
                    </div>
                    <x-form.group wide :label="__('wallet.direction')">
                        <select name="direction" required class="form-control">
                            <option value="credit" @selected(old('direction') === 'credit')>{{ __('wallet.credit_plus') }}</option>
                            <option value="debit" @selected(old('direction') === 'debit')>{{ __('wallet.debit_minus') }}</option>
                        </select>
                    </x-form.group>
                    <x-form.group wide :label="__('wallet.amount_in_currency', ['currency' => '—'])">
                        <input type="number" name="amount" id="charge-amount" step="0.01" min="0.01" required value="{{ old('amount') }}" class="form-control">
                        <p class="help-block mb-0" id="charge-amount-hint"></p>
                    </x-form.group>
                    <x-form.group wide :label="__('menu.requester_note')">
                        <textarea name="note" rows="2" class="form-control">{{ old('note') }}</textarea>
                    </x-form.group>
                    <x-form.actions>
                        <x-button type="submit" id="single-charge-submit">{{ __('wallet.apply_charge') }}</x-button>
                    </x-form.actions>
                </div>
            </form>
        </x-card>
    </div>

    <div class="col-md-6">
        <x-card>
            <h4 class="margin-top-none">{{ __('wallet.bulk_charge') }}</h4>
            <p class="help-block">{{ __('wallet.bulk_charge_hint') }}</p>
            <form method="POST" action="{{ route('admin.payment-requests.bulk-charge') }}">
                @csrf
                <div class="row">
                    <x-form.group wide :label="__('menu.amount').' ('.__('packages.toman').')'">
                        <input type="number" name="amount" step="0.01" min="1" required class="form-control">
                    </x-form.group>
                    <x-form.group wide :label="__('menu.requester_note')">
                        <textarea name="note" rows="2" class="form-control"></textarea>
                    </x-form.group>
                    <x-form.actions>
                        <x-button type="submit">{{ __('wallet.apply_bulk') }}</x-button>
                    </x-form.actions>
                </div>
            </form>
        </x-card>
    </div>
</div>

<p class="margin-top">
    <x-button :href="route('admin.payment-requests.index')" variant="secondary">{{ __('app.cancel') }}</x-button>
</p>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var userSelect = document.getElementById('charge-user-id');
    var currencySelect = document.getElementById('charge-currency');
    var balanceEl = document.getElementById('charge-user-balance');
    var amountHint = document.getElementById('charge-amount-hint');
    if (!userSelect || !currencySelect || !balanceEl) return;

    function parseJsonAttr(el, name) {
        try { return JSON.parse(el.getAttribute(name) || '{}'); } catch (e) { return {}; }
    }
    function parseJsonArray(el, name) {
        try { return JSON.parse(el.getAttribute(name) || '[]'); } catch (e) { return []; }
    }

    function syncCurrencyOptions() {
        var opt = userSelect.options[userSelect.selectedIndex];
        var enabled = opt ? parseJsonArray(opt, 'data-enabled') : ['IRT'];
        if (!enabled.length) enabled = ['IRT'];
        Array.prototype.forEach.call(currencySelect.options, function (option) {
            option.disabled = enabled.indexOf(option.value) === -1;
            option.hidden = option.disabled;
        });
        if (currencySelect.selectedOptions[0] && currencySelect.selectedOptions[0].disabled) {
            currencySelect.value = enabled[0];
        }
        syncBalance();
    }

    function syncBalance() {
        var opt = userSelect.options[userSelect.selectedIndex];
        var currencyOpt = currencySelect.options[currencySelect.selectedIndex];
        if (!opt || !currencyOpt) return;
        var balances = parseJsonAttr(opt, 'data-balances');
        var currency = currencyOpt.value;
        var symbol = currencyOpt.getAttribute('data-symbol') || currency;
        var amount = Number(balances[currency] || 0);
        balanceEl.textContent = amount.toLocaleString('fa-IR', { maximumFractionDigits: 2 }) + ' ' + symbol;
        if (amountHint) {
            amountHint.textContent = @json(__('ui.charge_on_wallet_prefix')) + ' ' + symbol;
        }
    }

    userSelect.addEventListener('change', syncCurrencyOptions);
    currencySelect.addEventListener('change', syncBalance);
    syncCurrencyOptions();

    var chargeForm = document.getElementById('single-charge-form');
    var chargeSubmit = document.getElementById('single-charge-submit');
    if (chargeForm && chargeSubmit) {
        chargeForm.addEventListener('submit', function () {
            chargeSubmit.disabled = true;
            chargeSubmit.textContent = @json(__('ui.submitting'));
        });
    }
});
</script>
@endpush
