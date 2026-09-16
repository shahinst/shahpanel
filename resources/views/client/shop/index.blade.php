@extends('layouts.panel')

@section('page_title', __('clients.buy_account'))

@section('panel_content')
<x-page-header :title="__('clients.buy_account')">
    <x-slot:actions>
        <x-button :href="route('client.payment-requests.create')" variant="secondary">{{ __('clients.charge_wallet') }}</x-button>
    </x-slot:actions>
</x-page-header>

@include('client.partials.payment-card-box', [
    'owner' => $owner,
    'ownerRoleLabel' => $ownerRoleLabel,
    'cards' => $cards ?? null,
    'showActions' => false,
])

@if ($catalog->isEmpty())
    <x-card>
        <div class="text-center py-4">
            <i class="bx bx-package fs-1 text-muted"></i>
            <p class="text-muted mb-0 mt-2">{{ __('clients.no_packages_available') }}</p>
        </div>
    </x-card>
@else
    <div class="row g-3">
        @foreach ($catalog->groupBy(fn ($row) => $row['package']->id) as $packageRows)
            @php
                $package = $packageRows->first()['package'];
            @endphp
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border shadow-none">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <h5 class="card-title mb-0">{{ $package->name }}</h5>
                            @if ($package->service_type)
                                <span class="badge bg-soft-primary text-primary">{{ $package->service_type->label() }}</span>
                            @endif
                        </div>

                        @if ($package->category)
                            <p class="text-muted small mb-3">{{ $package->category->name }}</p>
                        @endif

                        @php $isElastic = $package->isElastic(); @endphp
                        <form method="POST" action="{{ route('client.shop.store') }}" class="mt-auto shop-purchase-form" data-elastic="{{ $isElastic ? '1' : '0' }}"
                              data-currency-symbol="{{ $package->moneyCurrency()->symbol() }}"
                              data-currency-decimals="{{ $package->moneyCurrency()->displayDecimals() }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label small text-muted">{{ __('packages.select_duration') }}</label>
                                <select name="package_duration_id" class="form-control shop-duration" required>
                                    <option value="">— {{ __('packages.select_duration') }} —</option>
                                    @foreach ($packageRows as $row)
                                        <option value="{{ $row['duration']->id }}"
                                                data-price="{{ $row['display_price'] }}"
                                                @selected(old('package_duration_id') == $row['duration']->id)>
                                            {{ $row['duration']->displayLabel() }} —
                                            @if ($isElastic)
                                                {{ format_money($row['display_price'], $package->moneyCurrency()) }} / {{ __('packages.gb_unit') }}
                                            @else
                                                {{ format_money($row['display_price'], $package->moneyCurrency()) }}
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            @if ($isElastic)
                                <div class="mb-3">
                                    <label class="form-label small text-muted">{{ __('packages.choose_volume_gb') }}</label>
                                    <input type="number" name="data_gb" class="form-control shop-gb"
                                           step="1" min="{{ (float) ($package->min_data_gb ?? 1) }}"
                                           @if ($package->max_data_gb !== null) max="{{ (float) $package->max_data_gb }}" @endif
                                           value="{{ old('data_gb', (float) ($package->min_data_gb ?? 1)) }}" required>
                                    <p class="help-block text-muted small mb-0">
                                        @if ($package->max_data_gb !== null)
                                            {{ __('packages.gb_range_hint', ['min' => (float) ($package->min_data_gb ?? 1), 'max' => (float) $package->max_data_gb]) }}
                                        @else
                                            {{ __('packages.gb_min_hint', ['min' => (float) ($package->min_data_gb ?? 1)]) }}
                                        @endif
                                    </p>
                                    <div class="mt-2 fw-bold text-primary shop-total"></div>
                                </div>
                            @endif
                            <x-button type="submit" class="w-100">{{ __('clients.buy_now') }}</x-button>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif

@push('scripts')
<script>
document.querySelectorAll('.shop-purchase-form[data-elastic="1"]').forEach(function (form) {
    const durationSelect = form.querySelector('.shop-duration');
    const gbInput = form.querySelector('.shop-gb');
    const totalBox = form.querySelector('.shop-total');
    if (!durationSelect || !gbInput || !totalBox) return;

    const totalLabel = @json(__('packages.payable_total'));
    const priceMissingHint = @json(__('packages.elastic_price_missing_hint'));
    const gbUnitLabel = @json(__('packages.gb_unit'));
    // نماد و تعداد اعشار ارز از خود بسته خوانده می‌شود؛ واحد ثابت «تومان» برای بسته‌های ارزی نادرست بود.
    const currencySymbol = form.dataset.currencySymbol || '';
    const currencyDecimals = parseInt(form.dataset.currencyDecimals || '0', 10);

    function formatMoney(value) {
        return Number(value || 0).toLocaleString(@json(locale_tag()), {
            minimumFractionDigits: currencyDecimals,
            maximumFractionDigits: currencyDecimals,
        }) + ' ' + currencySymbol;
    }

    function recompute() {
        const opt = durationSelect.options[durationSelect.selectedIndex];
        const unit = opt ? parseFloat(opt.dataset.price || '0') : 0;
        const gb = parseFloat(gbInput.value || '0');
        if (!opt || !opt.value) {
            totalBox.textContent = '';
            return;
        }
        if (gb <= 0) {
            totalBox.textContent = '';
            return;
        }
        if (unit <= 0) {
            totalBox.textContent = priceMissingHint;
            return;
        }
        const total = unit * gb;
        totalBox.textContent = totalLabel + ': '
            + formatMoney(total)
            + ' (' + gb.toLocaleString(@json(locale_tag())) + ' ' + gbUnitLabel + ' × '
            + formatMoney(unit) + ' / ' + gbUnitLabel + ')';
    }

    durationSelect.addEventListener('change', recompute);
    gbInput.addEventListener('input', recompute);
    recompute();
});
</script>
@endpush
@endsection
