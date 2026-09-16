@php $user = $user ?? null; @endphp

@if ($user?->role === \App\Enums\UserRole::Agent || (! $user && request()->routeIs('admin.users.create')))
<x-form.group :label="__('sellers.daily_server_changes')" :hint="__('sellers.daily_server_changes_hint')">
    <input name="daily_server_change_limit" type="number" min="0" max="100" class="form-control"
           value="{{ old('daily_server_change_limit', $user?->daily_server_change_limit ?? 5) }}">
</x-form.group>
@php
    $enabledCurrencies = old('enabled_currencies', $user?->enabledCurrencyCodes() ?? [\App\Enums\MoneyCurrency::IRT->value]);
    if (! is_array($enabledCurrencies)) {
        $enabledCurrencies = [\App\Enums\MoneyCurrency::IRT->value];
    }
@endphp
<x-form.group :label="__('wallet.enabled_currencies')" :hint="__('wallet.enabled_currencies_hint')">
    <div class="d-flex flex-wrap gap-3">
        @foreach (\App\Enums\MoneyCurrency::sellable() as $currencyOption)
            <label class="form-check form-check-inline mb-0">
                <input type="checkbox"
                       class="form-check-input"
                       name="enabled_currencies[]"
                       value="{{ $currencyOption->value }}"
                       @checked(in_array($currencyOption->value, $enabledCurrencies, true))>
                <span class="form-check-label">{{ $currencyOption->label() }} ({{ $currencyOption->symbol() }})</span>
            </label>
        @endforeach
    </div>
</x-form.group>
@endif

@if ($user?->role === \App\Enums\UserRole::Seller || request()->routeIs('admin.sellers.*') || request()->routeIs('agent.sellers.*'))
@php
    $parentForCurrency = $parents?->firstWhere('id', (int) old('parent_id', $user?->parent_id))
        ?? $user?->parent
        ?? ($parents[0] ?? null);
    $parentCurrencies = $parentForCurrency
        ? $parentForCurrency->enabledCurrencyCodes()
        : [\App\Enums\MoneyCurrency::IRT->value];
    if ($parentCurrencies === []) {
        $parentCurrencies = [\App\Enums\MoneyCurrency::IRT->value];
    }
    $settlementCurrency = old('settlement_currency', $user?->settlement_currency ?? ($parentCurrencies[0] ?? \App\Enums\MoneyCurrency::IRT->value));
    if (! in_array($settlementCurrency, $parentCurrencies, true)) {
        $settlementCurrency = $parentCurrencies[0];
    }
@endphp
<x-form.group :label="__('wallet.settlement_currency')" :hint="__('wallet.settlement_currency_hint')">
    <select name="settlement_currency" id="settlement-currency" class="form-control" required>
        @foreach (\App\Enums\MoneyCurrency::sellable() as $currencyOption)
            @if (in_array($currencyOption->value, $parentCurrencies, true))
                <option value="{{ $currencyOption->value }}" @selected($settlementCurrency === $currencyOption->value)>
                    {{ $currencyOption->label() }} ({{ $currencyOption->symbol() }})
                </option>
            @endif
        @endforeach
    </select>
</x-form.group>
@if (! empty($parents) && request()->routeIs('admin.sellers.*'))
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var parentSelect = document.querySelector('select[name="parent_id"]');
    var settlementSelect = document.getElementById('settlement-currency');
    if (!parentSelect || !settlementSelect) return;

    var parentMap = {
        @foreach ($parents as $parentOption)
            {{ $parentOption->id }}: @json($parentOption->enabledCurrencyCodes()),
        @endforeach
    };
    var labels = {
        @foreach (\App\Enums\MoneyCurrency::sellable() as $currencyOption)
            '{{ $currencyOption->value }}': @json($currencyOption->label().' ('.$currencyOption->symbol().')'),
        @endforeach
    };

    function refreshSettlement() {
        var codes = parentMap[parentSelect.value] || ['IRT'];
        var current = settlementSelect.value;
        settlementSelect.innerHTML = '';
        codes.forEach(function (code) {
            var opt = document.createElement('option');
            opt.value = code;
            opt.textContent = labels[code] || code;
            if (code === current) opt.selected = true;
            settlementSelect.appendChild(opt);
        });
        if (!codes.includes(settlementSelect.value) && codes.length) {
            settlementSelect.value = codes[0];
        }
    }

    parentSelect.addEventListener('change', refreshSettlement);
});
</script>
@endpush
@endif
@endif

<x-form.group :label="__('auth.username')">
    <input name="username" value="{{ old('username', $user?->username) }}" required class="form-control">
</x-form.group>
<x-form.group :label="__('auth.email')">
    <input name="email" type="email" value="{{ old('email', $user?->email) }}" required class="form-control">
</x-form.group>
<x-form.group :label="__('validation.attributes.full_name')">
    <input name="full_name" value="{{ old('full_name', $user?->full_name) }}" required class="form-control">
</x-form.group>
<x-form.group :label="__('validation.attributes.phone')">
    <input name="phone" value="{{ old('phone', $user?->phone) }}" class="form-control">
</x-form.group>
<x-form.group :label="__('auth.password')">
    <input name="password" type="password" {{ $user ? '' : 'required' }} class="form-control">
</x-form.group>
<x-form.group :label="__('ui.confirm_x', [':field' => __('auth.password')])">
    <input name="password_confirmation" type="password" class="form-control">
</x-form.group>
<x-form.group :label="__('app.status')">
    <select name="status" class="form-control">
        @foreach (\App\Enums\UserStatus::cases() as $status)
            <option value="{{ $status->value }}" @selected(old('status', $user?->status?->value) === $status->value)>{{ $status->value }}</option>
        @endforeach
    </select>
</x-form.group>

@if (discount_pricing_enabled() && ($user?->role === \App\Enums\UserRole::Agent || (! $user && request()->routeIs('admin.users.create'))))
    <x-form.group label="{{ __('ui.agent_default_discount_label') }}" hint="{{ __('ui.agent_default_discount_hint') }}">
        <input name="reseller_discount_percent" type="number" step="0.01" min="0" max="100" dir="ltr" class="form-control"
               value="{{ old('reseller_discount_percent', $user?->reseller_discount_percent) }}"
               placeholder="{{ __('ui.eg_40') }}">
    </x-form.group>
    <x-form.group label="{{ __('ui.seller_markup_range_label') }}" hint="{{ __('ui.seller_markup_range_hint') }}">
        <input name="seller_markup_range_percent" type="number" step="0.01" min="0" max="1000" dir="ltr" class="form-control"
               value="{{ old('seller_markup_range_percent', $user?->seller_markup_range_percent) }}"
               placeholder="{{ __('ui.eg_30_empty_disabled') }}">
    </x-form.group>
@endif

@if ($user?->role === \App\Enums\UserRole::Agent || (! $user && request()->routeIs('admin.users.create')))
    @include('shared.users._package_assignment')
@endif
