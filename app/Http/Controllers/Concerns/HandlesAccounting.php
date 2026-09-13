<?php

namespace App\Http\Controllers\Concerns;

use App\Services\AccountingExportService;
use App\Services\AccountingService;
use App\Services\AgentServerChangeLimitService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait HandlesAccounting
{
    protected function accountingFilters(Request $request): array
    {
        return array_filter([
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'search' => $request->query('search'),
            'status' => $request->query('status'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function accountingIndexView(
        Request $request,
        AccountingService $accountingService,
        string $view,
        ?int $serverChangesRemaining = null
    ): View {
        $filters = $this->accountingFilters($request);
        $entries = $accountingService->paginateBillingEntries($request->user(), $filters);
        $entryCollection = $entries->getCollection();
        $summary = $accountingService->summarizeInvoicesForViewer($request->user(), $entryCollection);
        $eventLabels = $accountingService->eventLabelsForInvoices($entryCollection);
        $totals = $accountingService->totalsForViewer($request->user(), $entryCollection, $summary);

        return view($view, [
            'entries' => $entries,
            'eventLabels' => $eventLabels,
            'summary' => $summary,
            'totals' => $totals,
            'filters' => $filters,
            'serverChangesRemaining' => $serverChangesRemaining,
            'showMarginPercent' => $accountingService->showsMarginPercentColumn($request->user()),
        ]);
    }

    protected function accountingExportResponse(
        Request $request,
        AccountingService $accountingService,
        AccountingExportService $exportService
    ): StreamedResponse {
        $format = $request->query('format', 'csv');
        $filters = $this->accountingFilters($request);

        if ($format === 'pdf') {
            return $exportService->downloadPdf($request->user(), $filters, $accountingService);
        }

        return $exportService->downloadExcel($request->user(), $filters, $accountingService);
    }
}
