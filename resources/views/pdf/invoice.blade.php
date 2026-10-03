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
</head>
<body>
@php
    // TCPDF reads inline styles and simple attributes reliably, so the layout
    // is written with them rather than with class selectors.
    $muted = 'color:#6b7280;';
    $start = locale_is_rtl() ? 'right' : 'left';
    $end = locale_is_rtl() ? 'left' : 'right';
    $badge = match ($statusClass) {
        'paid' => 'background-color:#dcfce7;color:#166534;',
        'cancelled' => 'background-color:#fee2e2;color:#991b1b;',
        default => 'background-color:#fef3c7;color:#92400e;',
    };
    $boxTitle = 'background-color:#eef2ff;color:#312e81;font-weight:bold;font-size:10.5pt;';
@endphp
<table cellpadding="0" cellspacing="0" style="width:100%;">
    <tr>
        <td style="width:55%;">
            <span style="font-size:17pt;font-weight:bold;color:#312e81;">{{ $panel['name'] }}</span>
            @if ($panel['url'])<br><span style="{{ $muted }}font-size:8.5pt;">{{ $panel['url'] }}</span>@endif
            @if ($panel['phone'] || $panel['telegram'])
                <br><span style="{{ $muted }}font-size:8.5pt;">@if ($panel['phone']){{ __('accounting.pdf_phone') }}: {{ persian_digits($panel['phone']) }}@endif @if ($panel['phone'] && $panel['telegram']) · @endif @if ($panel['telegram']){{ __('accounting.pdf_telegram') }}: {{ $panel['telegram'] }}@endif</span>
            @endif
        </td>
        <td style="width:45%;">
            <table cellpadding="2" cellspacing="0" style="width:100%;">
                <tr><td colspan="2"><span style="font-size:15pt;font-weight:bold;">{{ __('ui.sales_invoice') }}</span> <span style="{{ $badge }}font-size:8.5pt;font-weight:bold;"> {{ $invoice->status->label() }} </span></td></tr>
                <tr><td style="{{ $muted }}width:35%;">{{ __('accounting.pdf_number') }}</td><td style="width:65%;"><b>{{ $invoice->invoice_number }}</b></td></tr>
                <tr><td style="{{ $muted }}">{{ __('ui.issue_date') }}</td><td>{{ jalali_date($invoice->issued_at ?? $invoice->created_at, 'Y/m/d H:i') }}</td></tr>
                <tr><td style="{{ $muted }}">{{ __('ui.col_type') }}</td><td>{{ $typeLabel }}</td></tr>
            </table>
        </td>
    </tr>
</table>
<table cellpadding="0" cellspacing="0" style="width:100%;"><tr><td style="height:3px;background-color:#4f46e5;font-size:2pt;">&nbsp;</td></tr></table>
<br>

<table cellpadding="0" cellspacing="6" style="width:100%;">
    <tr>
        <td style="width:50%;">
            <table cellpadding="5" cellspacing="0" style="width:100%;border:1px solid #dfe3ea;">
                <tr><td colspan="2" style="{{ $boxTitle }}">{{ __('accounting.pdf_parties') }}</td></tr>
                <tr><td style="{{ $muted }}width:38%;">{{ __('ui.buyer') }}</td><td style="width:62%;">{{ $party($invoice->buyer) }}</td></tr>
                @if ($invoice->buyer?->username)<tr><td style="{{ $muted }}">{{ __('accounting.pdf_username') }}</td><td>{{ $invoice->buyer->username }}</td></tr>@endif
                @if ($invoice->buyer?->phone)<tr><td style="{{ $muted }}">{{ __('accounting.pdf_phone') }}</td><td>{{ persian_digits($invoice->buyer->phone) }}</td></tr>@endif
                @if ($invoice->seller && $invoice->seller_user_id !== $invoice->buyer_user_id)<tr><td style="{{ $muted }}">{{ __('roles.seller') }}</td><td>{{ $party($invoice->seller) }}</td></tr>@endif
                @if ($invoice->agent)<tr><td style="{{ $muted }}">{{ __('roles.agent') }}</td><td>{{ $party($invoice->agent) }}</td></tr>@endif
                @if ($client)<tr><td style="{{ $muted }}">{{ __('accounting.pdf_client') }}</td><td>{{ $party($client) }}</td></tr>@endif
            </table>
        </td>
        <td style="width:50%;">
            <table cellpadding="5" cellspacing="0" style="width:100%;border:1px solid #dfe3ea;">
                <tr><td colspan="2" style="{{ $boxTitle }}">{{ __('accounting.pdf_service') }}</td></tr>
                @if ($account)
                    <tr><td style="{{ $muted }}width:38%;">{{ __('ui.col_account') }}</td><td style="width:62%;">{{ $account->remote_username }}</td></tr>
                    <tr><td style="{{ $muted }}">{{ __('accounting.pdf_service_type') }}</td><td>{{ $account->service_type->label() }}</td></tr>
                    @if ($account->server)<tr><td style="{{ $muted }}">{{ __('accounts.server') }}</td><td>{{ $account->server->name }}</td></tr>@endif
                    @if ($account->package)<tr><td style="{{ $muted }}">{{ __('accounts.package') }}</td><td>{{ $account->package->name }}</td></tr>@endif
                    @if ($account->packageDuration)<tr><td style="{{ $muted }}">{{ __('accounting.pdf_duration') }}</td><td>{{ $account->packageDuration->tier->label() }}</td></tr>@endif
                    <tr><td style="{{ $muted }}">{{ __('accounting.pdf_volume') }}</td><td>{{ $account->isUnlimited() ? __('dashboard.unlimited') : persian_digits(format_data_size((int) $account->data_limit_bytes)) }}</td></tr>
                    @if ($account->expiry_at)<tr><td style="{{ $muted }}">{{ __('accounts.expiry') }}</td><td>{{ jalali_date($account->expiry_at, 'Y/m/d') }}</td></tr>@endif
                @else
                    <tr><td colspan="2" style="{{ $muted }}">—</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>
<br>

@php
    $th = 'background-color:#eef2ff;color:#312e81;font-weight:bold;font-size:9pt;border:1px solid #c7d2fe;text-align:center;';
    $td = 'border:1px solid #e5e7eb;';
    $w = ['6%', '40%', '14%', '20%', '20%'];
@endphp
<table cellpadding="6" cellspacing="0" style="width:100%;">
    <tr>
        <td style="{{ $th }}width:{{ $w[0] }};">#</td>
        <td style="{{ $th }}width:{{ $w[1] }};">{{ __('ui.col_description') }}</td>
        <td style="{{ $th }}width:{{ $w[2] }};">{{ __('ui.col_quantity') }}</td>
        <td style="{{ $th }}width:{{ $w[3] }};">{{ __('ui.col_unit_price') }}</td>
        <td style="{{ $th }}width:{{ $w[4] }};">{{ __('ui.col_subtotal') }}</td>
    </tr>
    @foreach ($invoice->items as $i => $item)
        @php $bg = $i % 2 === 1 ? 'background-color:#f9fafb;' : ''; @endphp
        <tr>
            <td style="{{ $td }}{{ $bg }}width:{{ $w[0] }};text-align:center;">{{ persian_digits($i + 1) }}</td>
            <td style="{{ $td }}{{ $bg }}width:{{ $w[1] }};">{{ $item->description }}</td>
            <td style="{{ $td }}{{ $bg }}width:{{ $w[2] }};text-align:center;">{{ persian_digits($item->quantity) }}</td>
            <td style="{{ $td }}{{ $bg }}width:{{ $w[3] }};text-align:center;">{{ format_money($item->unit_price, $currency) }}</td>
            <td style="{{ $td }}{{ $bg }}width:{{ $w[4] }};text-align:center;">{{ format_money($item->total, $currency) }}</td>
        </tr>
    @endforeach
</table>
<br>

<table cellpadding="0" cellspacing="0" style="width:100%;">
    <tr>
        <td style="width:55%;">
            @if ($amountInWords)
                <table cellpadding="6" cellspacing="0" style="width:96%;"><tr><td style="background-color:#f5f3ff;border:1px solid #ddd6fe;"><span style="{{ $muted }}">{{ __('accounting.pdf_in_words') }}:</span> <b>{{ $amountInWords }}</b></td></tr></table>
            @endif
        </td>
        <td style="width:45%;">
            <table cellpadding="5" cellspacing="0" style="width:100%;">
                <tr><td style="color:#4b5563;width:50%;">{{ __('accounting.pdf_subtotal') }}</td><td style="width:50%;text-align:{{ $end }};">{{ format_money($invoice->subtotal, $currency) }}</td></tr>
                @if (bccomp((string) $invoice->subtotal, (string) $invoice->total, 2) !== 0)
                    <tr><td style="color:#4b5563;">{{ __('accounting.pdf_adjustment') }}</td><td style="text-align:{{ $end }};">{{ format_money(bcsub((string) $invoice->total, (string) $invoice->subtotal, 2), $currency) }}</td></tr>
                @endif
                <tr><td style="background-color:#312e81;color:#ffffff;font-weight:bold;font-size:11pt;">{{ __('accounting.pdf_total') }}</td><td style="background-color:#312e81;color:#ffffff;font-weight:bold;font-size:11pt;text-align:{{ $end }};">{{ format_money($invoice->total, $currency) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@if ($payments->isNotEmpty())
    <br><br>
    <table cellpadding="5" cellspacing="0" style="width:100%;border:1px solid #dfe3ea;">
        <tr><td colspan="3" style="{{ $boxTitle }}">{{ __('accounting.pdf_payments') }}</td></tr>
        @foreach ($payments as $payment)
            <tr>
                <td style="{{ $muted }}width:35%;">{{ jalali_date($payment->created_at, 'Y/m/d H:i') }}</td>
                <td style="width:35%;">{{ __('accounting.pdf_wallet_debit') }}</td>
                <td style="width:30%;text-align:{{ $end }};">{{ format_money($payment->amount, $payment->currency ?? $currency) }}</td>
            </tr>
        @endforeach
    </table>
@endif

<br><br>
<span style="{{ $muted }}font-size:8.5pt;">{{ __('accounting.pdf_generated', ['date' => jalali_date(now(), 'Y/m/d H:i')]) }}</span>
</body>
</html>
