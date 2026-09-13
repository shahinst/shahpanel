@props(['cards', 'payee' => null])

@if ($cards->isNotEmpty())
<div class="panel-modern-card mb-4">
    <div class="card-head">
        <h3>{{ __('payment_cards.upline_cards_title') }}</h3>
    </div>
    <div class="card-body">
        @if ($payee)
            <p class="text-muted small mb-3">{{ __('payment_cards.upline_cards_hint') }} — {{ $payee->full_name ?: $payee->username }}</p>
        @endif
        @foreach ($cards as $card)
            <div class="border rounded p-3 mb-2">
                <code dir="ltr" class="d-block mb-1">{{ $card->card_number }}</code>
                @if ($card->bank_name)<div class="small text-muted">{{ $card->bank_name }}</div>@endif
                @if ($card->card_holder)<div class="small">{{ $card->card_holder }}</div>@endif
                @if ($card->instructions)<div class="small mt-2 text-muted">{{ $card->instructions }}</div>@endif
            </div>
        @endforeach
    </div>
</div>
@endif
