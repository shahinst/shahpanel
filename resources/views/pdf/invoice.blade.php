@php
    /** @var \App\Models\Invoice $invoice */
    $currency = $invoice->currency;
    $account = $invoice->account;
    $typeLabel = __('accounting.invoice_type_'.$invoice->type->value);
    $party = fn (?\App\Models\User $u): string => $u ? ($u->full_name ?: $u->username) : '—';
    $statusClass = match ($invoice->status->value) {
        'paid' => 'paid',
        'cancelled' => 'cancelled',
        default => 'pending',
    };
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ locale_dir() }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 10pt; color: #1f2937; }
        .muted { color: #6b7280; }
        .small { font-size: 8.5pt; }
        table { border-collapse: collapse; width: 100%; }
        .head td { vertical-align: top; }
        .brand { font-size: 17pt; font-weight: bold; color: #312e81; }
        .doc-title { font-size: 15pt; font-weight: bold; color: #111827; }
        .badge { padding: 2px 10px; border-radius: 10px; font-size: 8.5pt; font-weight: bold; }
        .badge.paid { background: #dcfce7; color: #166534; }
        .badge.pending { background: #fef3c7; color: #92400e; }
        .badge.cancelled { background: #fee2e2; color: #991b1b; }
        .bar { height: 4px; background: #4f46e5; margin: 10px 0 14px; }
        .meta td { padding: 3px 0; }
        .meta .k { color: #6b7280; width: 32%; }
        table.box { border: 1px solid #dfe3ea; }
        table.box td.box-title { border-bottom: 1px solid #dfe3ea; }
        .box > tbody > tr > td, .box > tr > td { padding: 6px 10px; }
        .box-title { font-weight: bold; color: #312e81; font-size: 10.5pt; background: #eef2ff; }
        .grid td.cell { width: 50%; vertical-align: top; padding: 0 4px; }
        .kv td { padding: 2.5px 0; }
        .kv .k { color: #6b7280; width: 38%; }
        .items th { background: #eef2ff; color: #312e81; font-weight: bold; padding: 7px 6px; border: 1px solid #c7d2fe; font-size: 9pt; }
        .items td { padding: 7px 6px; border: 1px solid #e5e7eb; }
        .items tr.alt td { background: #f9fafb; }
        .num { text-align: center; }
        .money { text-align: left; white-space: nowrap; }
        .totals td { padding: 5px 8px; }
        .totals .k { color: #4b5563; }
        .totals .grand td { background: #312e81; color: #fff; font-weight: bold; font-size: 11pt; }
        .words { background: #f5f3ff; border: 1px solid #ddd6fe; padding: 7px 10px; }
        .footer { border-top: 1px solid #e5e7eb; padding-top: 6px; color: #9ca3af; font-size: 8pt; }
    </style>
</head>
<body>
    <htmlpagefooter name="invoiceFooter">
        <table class="footer"><tr>
            <td>{{ $panel['name'] }} @if ($panel['url']) — {{ $panel['url'] }} @endif</td>
            <td style="text-align: left;">{{ __('accounting.pdf_page', ['page' => '{PAGENO}', 'pages' => '{nbpg}']) }}</td>
        </tr></table>
    </htmlpagefooter>
    <sethtmlpagefooter name="invoiceFooter" value="on" />

    <table class="head">
        <tr>
            <td style="width: 55%;">
                <div class="brand">{{ $panel['name'] }}</div>
                @if ($panel['url'])<div class="muted small">{{ $panel['url'] }}</div>@endif
                @if ($panel['phone'] || $panel['telegram'])
                    <div class="muted small">
                        @if ($panel['phone']){{ __('accounting.pdf_phone') }}: {{ persian_digits($panel['phone']) }}@endif
                        @if ($panel['phone'] && $panel['telegram']) · @endif
                        @if ($panel['telegram']){{ __('accounting.pdf_telegram') }}: {{ $panel['telegram'] }}@endif
                    </div>
                @endif
            </td>
            <td style="width: 45%;">
                <table class="meta">
                    <tr><td colspan="2"><span class="doc-title">{{ __('ui.sales_invoice') }}</span> &nbsp; <span class="badge {{ $statusClass }}">{{ $invoice->status->label() }}</span></td></tr>
                    <tr><td class="k">{{ __('accounting.pdf_number') }}</td><td><b>{{ persian_digits($invoice->invoice_number) }}</b></td></tr>
                    <tr><td class="k">{{ __('ui.issue_date') }}</td><td>{{ jalali_date($invoice->issued_at ?? $invoice->created_at, 'Y/m/d H:i') }}</td></tr>
                    <tr><td class="k">{{ __('ui.col_type') }}</td><td>{{ $typeLabel }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
    <div class="bar"></div>

    <table class="grid">
        <tr>
            <td class="cell">
                <table class="box"><tr><td class="box-title">{{ __('accounting.pdf_parties') }}</td></tr><tr><td>
                    <table class="kv">
                        <tr><td class="k">{{ __('ui.buyer') }}</td><td>{{ $party($invoice->buyer) }}</td></tr>
                        @if ($invoice->buyer?->username)<tr><td class="k">{{ __('accounting.pdf_username') }}</td><td>{{ $invoice->buyer->username }}</td></tr>@endif
                        @if ($invoice->buyer?->phone)<tr><td class="k">{{ __('accounting.pdf_phone') }}</td><td>{{ persian_digits($invoice->buyer->phone) }}</td></tr>@endif
                        @if ($invoice->seller && $invoice->seller_user_id !== $invoice->buyer_user_id)<tr><td class="k">{{ __('roles.seller') }}</td><td>{{ $party($invoice->seller) }}</td></tr>@endif
                        @if ($invoice->agent)<tr><td class="k">{{ __('roles.agent') }}</td><td>{{ $party($invoice->agent) }}</td></tr>@endif
                        @if ($client)<tr><td class="k">{{ __('accounting.pdf_client') }}</td><td>{{ $party($client) }}</td></tr>@endif
                    </table>
                </td></tr></table>
            </td>
            <td class="cell">
                <table class="box"><tr><td class="box-title">{{ __('accounting.pdf_service') }}</td></tr><tr><td>
                    @if ($account)
                        <table class="kv">
                            <tr><td class="k">{{ __('ui.col_account') }}</td><td>{{ $account->remote_username }}</td></tr>
                            <tr><td class="k">{{ __('accounting.pdf_service_type') }}</td><td>{{ $account->service_type->label() }}</td></tr>
                            @if ($account->server)<tr><td class="k">{{ __('accounts.server') }}</td><td>{{ $account->server->name }}</td></tr>@endif
                            @if ($account->package)<tr><td class="k">{{ __('accounts.package') }}</td><td>{{ $account->package->name }}</td></tr>@endif
                            @if ($account->packageDuration)<tr><td class="k">{{ __('accounting.pdf_duration') }}</td><td>{{ $account->packageDuration->tier->label() }}</td></tr>@endif
                            <tr><td class="k">{{ __('accounting.pdf_volume') }}</td><td>{{ $account->isUnlimited() ? __('dashboard.unlimited') : persian_digits(format_data_size((int) $account->data_limit_bytes)) }}</td></tr>
                            @if ($account->expiry_at)<tr><td class="k">{{ __('accounts.expiry') }}</td><td>{{ jalali_date($account->expiry_at, 'Y/m/d') }}</td></tr>@endif
                        </table>
                    @else
                        <div class="muted">—</div>
                    @endif
                </td></tr></table>
            </td>
        </tr>
    </table>

    <br>
    <table class="items">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th>{{ __('ui.col_description') }}</th>
                <th style="width: 10%;">{{ __('ui.col_quantity') }}</th>
                <th style="width: 20%;">{{ __('ui.col_unit_price') }}</th>
                <th style="width: 20%;">{{ __('ui.col_subtotal') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $i => $item)
                <tr @class(['alt' => $i % 2 === 1])>
                    <td class="num">{{ persian_digits($i + 1) }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ persian_digits($item->quantity) }}</td>
                    <td class="money">{{ format_money($item->unit_price, $currency) }}</td>
                    <td class="money">{{ format_money($item->total, $currency) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-top: 8px;">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                @if ($amountInWords)
                    <div class="words"><span class="muted">{{ __('accounting.pdf_in_words') }}:</span> <b>{{ $amountInWords }}</b></div>
                @endif
            </td>
            <td style="width: 45%; vertical-align: top;">
                <table class="totals">
                    <tr><td class="k">{{ __('accounting.pdf_subtotal') }}</td><td class="money">{{ format_money($invoice->subtotal, $currency) }}</td></tr>
                    @if (bccomp((string) $invoice->subtotal, (string) $invoice->total, 2) !== 0)
                        <tr><td class="k">{{ __('accounting.pdf_adjustment') }}</td><td class="money">{{ format_money(bcsub((string) $invoice->total, (string) $invoice->subtotal, 2), $currency) }}</td></tr>
                    @endif
                    <tr class="grand"><td>{{ __('accounting.pdf_total') }}</td><td class="money">{{ format_money($invoice->total, $currency) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($payments->isNotEmpty())
        <br>
        <table class="box"><tr><td class="box-title">{{ __('accounting.pdf_payments') }}</td></tr><tr><td>
            <table class="kv">
                @foreach ($payments as $payment)
                    <tr>
                        <td class="k">{{ jalali_date($payment->created_at, 'Y/m/d H:i') }}</td>
                        <td>{{ __('accounting.pdf_wallet_debit') }}</td>
                        <td class="money">{{ format_money($payment->amount, $payment->currency ?? $currency) }}</td>
                    </tr>
                @endforeach
            </table>
        </td></tr></table>
    @endif

    <p class="muted small" style="margin-top: 14px;">{{ __('accounting.pdf_generated', ['date' => jalali_date(now(), 'Y/m/d H:i')]) }}</p>
</body>
</html>
