@php
    $viewer = auth()->user();
    $enabledCodes = $viewer?->enabledCurrencyCodes() ?? [\App\Enums\MoneyCurrency::IRT->value];
    $defaultCurrency = old(
        'currency',
        $viewer?->settlementMoneyCurrency()->value ?? \App\Enums\MoneyCurrency::IRT->value
    );
    if (! in_array($defaultCurrency, $enabledCodes, true)) {
        $defaultCurrency = $enabledCodes[0] ?? \App\Enums\MoneyCurrency::IRT->value;
    }
@endphp

@if (count($enabledCodes) > 1)
    <x-form.group :label="__('wallet.wallet_currency')">
        <select name="currency" class="form-control" required>
            @foreach (\App\Enums\MoneyCurrency::sellable() as $currencyOption)
                @if (in_array($currencyOption->value, $enabledCodes, true))
                    <option value="{{ $currencyOption->value }}" @selected($defaultCurrency === $currencyOption->value)>
                        {{ $currencyOption->label() }} ({{ $currencyOption->symbol() }})
                    </option>
                @endif
            @endforeach
        </select>
    </x-form.group>
@else
    <input type="hidden" name="currency" value="{{ $defaultCurrency }}">
@endif

<x-form.group :label="__('menu.amount')">
    <input name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" required class="form-control">
    <p class="help-block mb-0">
        @if (($defaultCurrency ?? 'IRT') === 'IRT')
            {{ __('ui.min_amount_irt_hint') }}
        @else
            {{ __('ui.amount_in_selected_currency_hint') }}
        @endif
    </p>
</x-form.group>
<x-form.group :label="__('menu.tracking_number')">
    <input name="tracking_number" value="{{ old('tracking_number') }}" required class="form-control">
</x-form.group>
<x-form.group :label="__('menu.card_last4')">
    <input name="card_last4" maxlength="4" value="{{ old('card_last4') }}" required class="form-control">
</x-form.group>
<x-form.group wide :label="__('menu.requester_note')">
    <textarea name="requester_note" rows="3" class="form-control">{{ old('requester_note') }}</textarea>
</x-form.group>
