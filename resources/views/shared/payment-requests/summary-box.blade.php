@php
    $cards = $chargeSummaryCards ?? [];
@endphp

@if ($cards !== [])
    <section class="payment-summary-box" aria-label="{{ __('wallet.summary_box_title') }}">
        <div class="payment-summary-box__head">
            <h2 class="payment-summary-box__title">{{ __('wallet.summary_box_title') }}</h2>
        </div>
        <div class="row g-3 payment-summary-box__grid">
            @foreach ($cards as $card)
                <div class="col-sm-6">
                    <div class="panel-kpi-mini panel-tone-{{ $card['tone'] ?? 'warning' }}">
                        <div class="label">{{ $card['label'] }}</div>
                        <p class="value">{{ persian_digits((string) ($card['value'] ?? 0)) }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif
