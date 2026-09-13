@extends('layouts.panel')

@section('page_title', __('clients.display_pricing'))

@section('panel_content')
<x-card>
    <x-alert type="info" class="mb-3">
        @if ($showHierarchyPricingNotice ?? false)
            {{ __('clients.display_pricing_audience_notice_hierarchy') }}
        @else
            {{ __('clients.display_pricing_audience_notice') }}
        @endif
    </x-alert>

    <div class="alert alert-secondary mb-4">
        <strong>{{ __('clients.retail_economics_box_title') }}</strong>
        <p class="mb-0 small">{{ __('clients.retail_economics_box_hint') }}</p>
    </div>

    <p class="text-muted">{{ __('clients.display_pricing_hint') }}</p>
    <form method="POST" action="{{ route($panel.'.client-pricing.update') }}">
        @csrf
        @method('PUT')

        @php
            $catalogGroups = $catalogGroups ?? collect();
            if ($catalogGroups->isEmpty() && ($catalog ?? collect())->isNotEmpty()) {
                $catalogGroups = app(\App\Services\PackageCategoryService::class)->groupCatalogRows($catalog);
            }
        @endphp

        @foreach ($catalogGroups as $group)
            <div class="mb-4">
                <h6 class="text-muted border-bottom pb-2 mb-3">{{ $group['label'] }}</h6>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>{{ __('accounts.package') }}</th>
                                <th>{{ __('clients.list_price') }}</th>
                                <th>{{ __('clients.display_price') }}</th>
                                <th>{{ __('clients.retail_profit') }}</th>
                                <th>{{ __('clients.show_in_portal') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group['rows'] as $row)
                                @php
                                    $duration = $row['duration'];
                                    $wholesale = $row['list_price'];
                                    $defaultDisplay = $row['is_custom'] ? $row['display_price'] : $wholesale;
                                @endphp
                                <tr data-wholesale="{{ $wholesale }}">
                                    <td>{{ $row['package']->name }} — {{ $duration->displayLabel() }}</td>
                                    <td class="text-muted">{{ format_toman($wholesale) }}</td>
                                    <td style="min-width: 9rem;">
                                        <input type="number" name="prices[{{ $duration->id }}][display_price]"
                                               value="{{ old('prices.'.$duration->id.'.display_price', $row['is_custom'] ? $row['display_price'] : '') }}"
                                               class="form-control form-control-sm client-display-price-input"
                                               min="0" step="1000" placeholder="{{ $wholesale }}"
                                               data-duration-id="{{ $duration->id }}">
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-success client-retail-profit" data-duration-id="{{ $duration->id }}">
                                            {{ format_toman($row['retail_profit']) }}
                                        </span>
                                    </td>
                                    <td>
                                        <input type="checkbox" name="prices[{{ $duration->id }}][is_visible]" value="1"
                                               @checked(old('prices.'.$duration->id.'.is_visible', $row['is_visible']))>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach

        <x-form.actions>
            <x-button type="submit">{{ __('app.save') }}</x-button>
        </x-form.actions>
    </form>
</x-card>
@endsection

@push('scripts')
<script>
(function () {
    function parseAmount(value) {
        var n = parseFloat(String(value || '').replace(/,/g, ''));
        return isNaN(n) ? 0 : n;
    }

    function formatToman(amount) {
        var n = Math.round(amount);
        return n.toLocaleString('fa-IR') + ' {{ config('vpnpanel.currency_label') }}';
    }

    function updateProfit(row) {
        var wholesale = parseAmount(row.getAttribute('data-wholesale'));
        var input = row.querySelector('.client-display-price-input');
        var badge = row.querySelector('.client-retail-profit');
        if (!input || !badge) return;
        var display = parseAmount(input.value);
        if (display <= 0) display = wholesale;
        var profit = Math.max(0, display - wholesale);
        badge.textContent = formatToman(profit);
    }

    document.querySelectorAll('tr[data-wholesale]').forEach(function (row) {
        var input = row.querySelector('.client-display-price-input');
        if (input) {
            input.addEventListener('input', function () { updateProfit(row); });
            updateProfit(row);
        }
    });
})();
</script>
@endpush
