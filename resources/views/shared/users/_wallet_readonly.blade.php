@php
    $wallets = collect($wallets ?? []);
    if ($wallets->isEmpty() && isset($wallet) && $wallet) {
        $wallets = collect([$wallet]);
    }
@endphp

<x-form.group wide :label="__('wallet.balance_readonly')">
    @forelse ($wallets as $row)
        @php
            $currency = \App\Enums\MoneyCurrency::normalize($row->currency ?? 'IRT');
            $balance = $row->balance ?? 0;
        @endphp
        <p class="form-control-static" style="font-size:18px;font-weight:700;margin-bottom:0.35rem;">
            {{ format_money($balance, $currency) }}
        </p>
    @empty
        <p class="form-control-static" style="font-size:18px;font-weight:700;">
            {{ format_money(0, \App\Enums\MoneyCurrency::IRT) }}
        </p>
    @endforelse
</x-form.group>
