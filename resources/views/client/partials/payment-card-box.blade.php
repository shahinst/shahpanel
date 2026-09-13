@props([
    'owner',
    'ownerRoleLabel' => null,
    'cards' => null,
    'showActions' => true,
])

@php
    $ownerRoleLabel = $ownerRoleLabel ?? $owner->role->label();
    $ownerName = $owner->full_name ?: $owner->username;
    $cards = $cards ?? app(\App\Services\PaymentCardService::class)->visiblePaymentCardsForClient(auth()->user());
    $showOwnerLabel = $cards->pluck('user_id')->unique()->count() > 1;
@endphp

<style>
.client-payment-card-box {
    border: 1px solid #dbeafe;
    border-radius: 16px;
    background: linear-gradient(180deg, #eff6ff 0%, #fff 100%);
    overflow: hidden;
    margin-bottom: 1.25rem;
}
.client-payment-card-box__header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    padding: 1.1rem 1.25rem 0;
}
.client-payment-card-box__body { padding: 1rem 1.25rem 1.25rem; }
.client-payment-card-item {
    background: #fff;
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    padding: .85rem 1rem;
    margin-bottom: .75rem;
}
.client-payment-card-item:last-child { margin-bottom: 0; }
.client-payment-card-box__meta {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: .5rem;
    font-size: .88rem;
}
.client-payment-card-box__meta .label,
.client-payment-card-box__number-wrap .label {
    color: #64748b;
    font-size: .78rem;
}
.client-payment-card-box__number {
    margin-top: .25rem;
    padding: .65rem .85rem;
    border-radius: 10px;
    background: #f8fafc;
    border: 1px solid #dbeafe;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 1.05rem;
    font-weight: 700;
    letter-spacing: .08em;
    direction: ltr;
    text-align: center;
}
.client-payment-card-box__instructions {
    margin-top: .65rem;
    font-size: .82rem;
    color: #475569;
    white-space: pre-line;
}
</style>

<div class="client-payment-card-box">
    <div class="client-payment-card-box__header">
        <div>
            <h5 class="mb-1">{{ __('clients.bank_card_info') }}</h5>
            <p class="text-muted small mb-0">{{ __('clients.bank_card_owner_hint', ['name' => $ownerName, 'role' => $ownerRoleLabel]) }}</p>
        </div>
        <span class="badge bg-soft-primary text-primary">{{ $ownerRoleLabel }}</span>
    </div>

    <div class="client-payment-card-box__body">
        @forelse ($cards as $card)
            <div class="client-payment-card-item">
                @if ($showOwnerLabel && $card->user)
                    <div class="mb-2"><span class="badge bg-soft-primary text-primary">{{ $card->user->role->label() }}</span></div>
                @endif
                @if ($card->bank_name)
                    <div class="client-payment-card-box__meta">
                        <span class="label">{{ __('clients.bank_name') }}</span>
                        <span>{{ $card->bank_name }}</span>
                    </div>
                @endif
                <div class="client-payment-card-box__number-wrap">
                    <span class="label">{{ __('clients.card_number') }}</span>
                    <div class="client-payment-card-box__number">{{ $card->card_number }}</div>
                </div>
                @if ($card->card_holder)
                    <div class="client-payment-card-box__meta">
                        <span class="label">{{ __('clients.card_holder') }}</span>
                        <span>{{ $card->card_holder }}</span>
                    </div>
                @endif
                @if ($card->instructions)
                    <div class="client-payment-card-box__instructions">{{ $card->instructions }}</div>
                @endif
                @if ($showActions)
                    <button type="button" class="btn btn-outline-primary btn-sm mt-2" data-copy-card="{{ preg_replace('/\s+/', '', $card->card_number) }}">
                        <i class="bx bx-copy align-middle"></i> {{ __('clients.copy_card') }}
                    </button>
                @endif
            </div>
        @empty
            <div class="alert alert-warning mb-0">
                {{ __('clients.payment_card_not_configured', ['role' => $ownerRoleLabel]) }}
            </div>
        @endforelse

        @if ($cards->isNotEmpty())
            <p class="text-muted small mb-0 mt-2">{{ __('clients.bank_card_hint') }}</p>
        @endif
    </div>
</div>

@if ($showActions && $cards->isNotEmpty())
    @push('scripts')
    <script>
    document.querySelectorAll('[data-copy-card]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy-card') || '';
            if (!text || !navigator.clipboard) return;
            navigator.clipboard.writeText(text).then(function () {
                btn.innerHTML = '<i class="bx bx-check align-middle"></i> {{ __('clients.copied') }}';
                setTimeout(function () {
                    btn.innerHTML = '<i class="bx bx-copy align-middle"></i> {{ __('clients.copy_card') }}';
                }, 1800);
            });
        });
    });
    </script>
    @endpush
@endif
