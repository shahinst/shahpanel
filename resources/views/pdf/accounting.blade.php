<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ locale_dir() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('menu.accounting') }}</title>
</head>
<body>
@php
    // Inline styles: TCPDF applies them reliably, unlike class selectors.
    $showCredited = $includeCredited ?? true;
    $showMarginPercent = $includeMarginPercent ?? false;
    $pdfHeaders = $headers ?? [];
    $labelColspan = max(1, count($pdfHeaders) - ($showCredited ? 1 : 0) - ($showMarginPercent ? 1 : 0) - 1);
    $th = 'background-color:#f8fafc;font-weight:bold;border:1px solid #e2e8f0;font-size:8.5pt;';
    $td = 'border:1px solid #e2e8f0;font-size:8.5pt;';
@endphp
<table cellpadding="2" cellspacing="0" style="width:100%;">
    <tr><td style="font-size:16pt;font-weight:bold;color:#2563eb;">{{ __('menu.accounting') }}</td></tr>
    <tr><td style="color:#64748b;font-size:9pt;border-bottom:2px solid #2563eb;">
        {{ $viewer->full_name }} — {{ jalali_date($generatedAt, 'Y/m/d H:i') }}
        @if (! empty($filters['date_from']) || ! empty($filters['date_to']))
            — {{ __('accounting.filter_period') }}:
            {{ $filters['date_from'] ?? '…' }} {{ __('ui.to_range') }} {{ $filters['date_to'] ?? '…' }}
        @endif
    </td></tr>
</table>
<br>
<table cellpadding="4" cellspacing="0" style="width:100%;">
    <thead>
        <tr>
            @foreach ($pdfHeaders as $header)
                <th style="{{ $th }}">{{ $header }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $i => $row)
            <tr>
                @foreach ($row as $cell)
                    <td style="{{ $td }}{{ $i % 2 === 1 ? 'background-color:#fafbfc;' : '' }}">{{ $cell }}</td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($pdfHeaders) }}" style="{{ $td }}">{{ __('app.no_results') }}</td></tr>
        @endforelse
        <tr>
            <td colspan="{{ $labelColspan }}" style="{{ $td }}font-weight:bold;background-color:#f1f5f9;">{{ __('accounting.export_totals') }}</td>
            @if ($showCredited)
                <td style="{{ $td }}font-weight:bold;background-color:#f1f5f9;">{{ collect($totals['by_currency'])->map(fn ($bucket, $code) => format_money($bucket['credited'], $code))->implode(' + ') }}</td>
            @endif
            @if ($showMarginPercent)
                <td style="{{ $td }}background-color:#f1f5f9;"></td>
            @endif
            <td style="{{ $td }}font-weight:bold;background-color:#f1f5f9;">{{ collect($totals['by_currency'])->map(fn ($bucket, $code) => format_money($bucket['debited'], $code))->implode(' + ') }}</td>
        </tr>
    </tbody>
</table>
</body>
</html>
