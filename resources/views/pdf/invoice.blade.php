<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>فاکتور {{ $invoice->invoice_number }}</title>
    @include('pdf.partials.vazirmatn-styles')
    <style>
        body {
            font-size: 13px;
            color: #111827;
            line-height: 1.6;
        }

        .header {
            border-bottom: 2px solid #2563eb;
            margin-bottom: 24px;
            padding-bottom: 12px;
        }

        .title {
            font-size: 22px;
            font-weight: 700;
            color: #2563eb;
        }

        .meta-table {
            width: 100%;
            margin-bottom: 24px;
        }

        .meta-table td {
            padding: 4px 0;
            vertical-align: top;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        .items-table th,
        .items-table td {
            border: 1px solid #d1d5db;
            padding: 8px 10px;
            text-align: right;
        }

        .items-table th {
            background: #f3f4f6;
        }

        .total-row td {
            font-weight: 700;
            background: #eff6ff;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">فاکتور فروش</div>
        <div>{{ $invoice->invoice_number }}</div>
    </div>

    <table class="meta-table">
        <tr>
            <td width="50%">
                <strong>خریدار:</strong> {{ $invoice->buyer?->full_name ?? $invoice->buyer?->username }}<br>
                <strong>فروشنده:</strong> {{ $invoice->seller?->full_name ?? $invoice->seller?->username }}<br>
                <strong>نماینده:</strong> {{ $invoice->agent?->full_name ?? $invoice->agent?->username }}
            </td>
            <td width="50%">
                <strong>تاریخ صدور:</strong> {{ jalali_date($invoice->issued_at) }}<br>
                <strong>نوع:</strong> {{ $invoice->type->value }}<br>
                <strong>وضعیت:</strong> {{ $invoice->status->value }}
            </td>
        </tr>
    </table>

    @if($invoice->account)
        <p>
            <strong>اکانت:</strong> {{ $invoice->account->remote_username }}
            @if($invoice->account->package)
                — {{ $invoice->account->package->name }}
            @endif
        </p>
    @endif

    <table class="items-table">
        <thead>
            <tr>
                <th>شرح</th>
                <th width="80">تعداد</th>
                <th width="120">قیمت واحد</th>
                <th width="120">جمع</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td>{{ persian_digits($item->quantity) }}</td>
                    {{-- ردیف فاکتور ستون currency ندارد؛ ارز از فاکتور والد خوانده می‌شود. --}}
                    <td>{{ format_money($item->unit_price, $invoice->currency) }}</td>
                    <td>{{ format_money($item->total, $invoice->currency) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="3">جمع کل</td>
                <td>{{ format_money($invoice->total, $invoice->currency) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
