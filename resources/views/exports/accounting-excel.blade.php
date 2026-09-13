<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40" lang="fa" dir="rtl">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    @php
        // Office XML must not contain literal "<x:" in this Blade file — Laravel
        // treats that as an anonymous component tag during view:cache.
        $x = 'x:';
        echo '<!--[if gte mso 9]><xml>';
        echo '<'.$x.'ExcelWorkbook><'.$x.'ExcelWorksheets><'.$x.'ExcelWorksheet>';
        echo '<'.$x.'Name>Accounting</'.$x.'Name>';
        echo '<'.$x.'WorksheetOptions><'.$x.'DisplayRightToLeft/></'.$x.'WorksheetOptions>';
        echo '</'.$x.'ExcelWorksheet></'.$x.'ExcelWorksheets></'.$x.'ExcelWorkbook>';
        echo '</xml><![endif]-->';
    @endphp
    <style>
        body, table, th, td {
            font-family: Vazirmatn, Tahoma, Arial, sans-serif;
            direction: rtl;
            text-align: right;
        }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #cbd5e1; padding: 6px 8px; }
        th { background: #f8fafc; font-weight: bold; }
        .meta { color: #64748b; margin-bottom: 12px; }
        .totals td { font-weight: bold; background: #f1f5f9; }
    </style>
</head>
<body>
    <div class="meta">
        <strong>{{ __('menu.accounting') }}</strong><br>
        {{ $viewer->full_name }} — {{ jalali_date($generatedAt, 'Y/m/d H:i') }}
        @if (! empty($filters['date_from']) || ! empty($filters['date_to']))
            — {{ __('accounting.filter_period') }}: {{ $filters['date_from'] ?? '…' }} تا {{ $filters['date_to'] ?? '…' }}
        @endif
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($headers as $header)
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
                <tr><td colspan="{{ count($headers) }}">{{ __('app.no_results') }}</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            @php
                $showMarginPercent = $includeMarginPercent ?? false;
                $labelColspan = max(1, count($headers) - ($includeCredited ?? true ? 1 : 0) - ($showMarginPercent ? 1 : 0) - 1);
            @endphp
            <tr class="totals">
                <td colspan="{{ $labelColspan }}">{{ __('accounting.export_totals') }}</td>
                @if ($includeCredited ?? true)
                    <td>{{ format_toman($totals['credited'] ?? 0) }}</td>
                @endif
                @if ($showMarginPercent)
                    <td></td>
                @endif
                <td>{{ format_toman($totals['debited'] ?? 0) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
