<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('menu.accounting') }}</title>
    @include('pdf.partials.vazirmatn-styles')
    <style>
        body {
            font-size: 11px;
            color: #111827;
        }
        .header {
            border-bottom: 2px solid #2563eb;
            margin-bottom: 16px;
            padding-bottom: 10px;
        }
        .title { font-size: 18px; font-weight: bold; color: #2563eb; font-family: vazirmatn, DejaVu Sans, sans-serif; }
        .meta { color: #64748b; font-size: 10px; margin-top: 4px; font-family: vazirmatn, DejaVu Sans, sans-serif; }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            border: 1px solid #e2e8f0;
            padding: 6px 8px;
            font-family: vazirmatn, DejaVu Sans, sans-serif;
        }
        th { background: #f8fafc; font-weight: bold; }
        .totals td { font-weight: bold; background: #f1f5f9; }
        tr:nth-child(even) td { background: #fafbfc; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">{{ __('menu.accounting') }}</div>
        <div class="meta">
            {{ $viewer->full_name }} — {{ jalali_date($generatedAt, 'Y/m/d H:i') }}
            @if (! empty($filters['date_from']) || ! empty($filters['date_to']))
                — {{ __('accounting.filter_period') }}:
                {{ $filters['date_from'] ?? '…' }} تا {{ $filters['date_to'] ?? '…' }}
            @endif
        </div>
    </div>

    @php
        $showCredited = $includeCredited ?? true;
        $showMarginPercent = $includeMarginPercent ?? false;
        $pdfHeaders = $headers ?? [];
        $labelColspan = max(1, count($pdfHeaders) - ($showCredited ? 1 : 0) - ($showMarginPercent ? 1 : 0) - 1);
    @endphp
    <table>
        <thead>
            <tr>
                @foreach ($pdfHeaders as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($pdfHeaders) }}">{{ __('app.no_results') }}</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="totals">
                <td colspan="{{ $labelColspan }}">{{ __('accounting.export_totals') }}</td>
                @if ($showCredited)
                    <td>{{ collect($totals['by_currency'])->map(fn ($bucket, $code) => format_money($bucket['credited'], $code))->implode(' + ') }}</td>
                @endif
                @if ($showMarginPercent)
                    <td></td>
                @endif
                <td>{{ collect($totals['by_currency'])->map(fn ($bucket, $code) => format_money($bucket['debited'], $code))->implode(' + ') }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
