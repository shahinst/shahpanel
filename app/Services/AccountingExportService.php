<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Support\PdfFontSetup;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountingExportService
{
    public function downloadExcel(User $viewer, array $filters, AccountingService $accountingService): StreamedResponse
    {
        $entries = $accountingService->listBillingEntries($viewer, $filters);
        $summary = $accountingService->summarizeInvoicesForViewer($viewer, $entries);
        $eventLabels = $accountingService->eventLabelsForInvoices($entries);
        $totals = $accountingService->totalsForViewer($viewer, $entries, $summary);
        $includeCredited = $accountingService->showsCreditedColumn($viewer);
        $includeMarginPercent = $accountingService->showsMarginPercentColumn($viewer);
        $headers = $this->headers($includeCredited, $includeMarginPercent);

        $rows = $entries->map(function (Invoice $invoice) use ($summary, $eventLabels, $accountingService, $includeCredited, $includeMarginPercent): array {
            $account = $invoice->account;

            if ($account === null) {
                return array_fill(0, count($this->headers($includeCredited, $includeMarginPercent)), '—');
            }

            return $accountingService->exportBillingRow(
                $invoice,
                $account,
                $eventLabels[$invoice->id] ?? '—',
                $summary[$invoice->id] ?? null,
                $includeCredited,
                $includeMarginPercent,
            );
        });

        $filename = 'accounting-'.now()->format('Y-m-d-His').'.xls';

        return response()->streamDownload(function () use ($rows, $totals, $filters, $viewer, $headers, $includeCredited, $includeMarginPercent): void {
            echo "\xEF\xBB\xBF";
            echo view('exports.accounting-excel', [
                'rows' => $rows,
                'totals' => $totals,
                'filters' => $filters,
                'viewer' => $viewer,
                'generatedAt' => now(),
                'headers' => $headers,
                'includeCredited' => $includeCredited,
                'includeMarginPercent' => $includeMarginPercent,
            ])->render();
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    public function downloadPdf(User $viewer, array $filters, AccountingService $accountingService): StreamedResponse
    {
        $entries = $accountingService->listBillingEntries($viewer, $filters);
        $summary = $accountingService->summarizeInvoicesForViewer($viewer, $entries);
        $eventLabels = $accountingService->eventLabelsForInvoices($entries);
        $totals = $accountingService->totalsForViewer($viewer, $entries, $summary);
        $includeCredited = $accountingService->showsCreditedColumn($viewer);
        $includeMarginPercent = $accountingService->showsMarginPercentColumn($viewer);
        $headers = $this->headers($includeCredited, $includeMarginPercent);

        $rows = $entries->map(function (Invoice $invoice) use ($summary, $eventLabels, $accountingService, $includeCredited, $includeMarginPercent): array {
            $account = $invoice->account;

            if ($account === null) {
                return array_fill(0, count($this->headers($includeCredited, $includeMarginPercent)), '—');
            }

            return $accountingService->exportBillingRow(
                $invoice,
                $account,
                $eventLabels[$invoice->id] ?? '—',
                $summary[$invoice->id] ?? null,
                $includeCredited,
                $includeMarginPercent,
            );
        });

        $pdf = Pdf::loadView('pdf.accounting', [
            'rows' => $rows,
            'totals' => $totals,
            'filters' => $filters,
            'viewer' => $viewer,
            'generatedAt' => now(),
            'includeCredited' => $includeCredited,
            'includeMarginPercent' => $includeMarginPercent,
            'headers' => $headers,
        ])->setPaper('a4', 'landscape');

        PdfFontSetup::configure($pdf);

        $filename = 'accounting-'.now()->format('Y-m-d-His').'.pdf';

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf']
        );
    }

    /**
     * @return list<string>
     */
    protected function headers(bool $includeCredited = true, bool $includeMarginPercent = false): array
    {
        $headers = [
            __('accounting.transaction_at'),
            __('accounting.event_type'),
            __('accounting.account_name'),
            __('accounting.service_type'),
            __('accounting.account_status'),
            __('accounting.expiry_at'),
            __('accounting.package'),
        ];

        if ($includeCredited) {
            $headers[] = __('accounting.amount_credited');
        }

        if ($includeMarginPercent) {
            $headers[] = __('accounting.margin_percent');
        }

        $headers[] = __('accounting.amount_deducted');

        return $headers;
    }
}
