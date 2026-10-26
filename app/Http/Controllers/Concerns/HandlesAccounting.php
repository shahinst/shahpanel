<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\UserRole;
use App\Models\User;
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
            'owner_id' => $this->accountingOwnerFilter($request),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The agent or seller whose accounts the viewer wants, and only one the
     * viewer may see: an admin picks any, an agent picks their own sellers.
     */
    protected function accountingOwnerFilter(Request $request): ?int
    {
        $ownerId = (int) $request->query('owner_id');

        if ($ownerId <= 0) {
            return null;
        }

        return $this->accountingOwnerOptions($request->user())->contains(fn (User $u): bool => $u->id === $ownerId) ? $ownerId : null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    protected function accountingOwnerOptions(User $viewer): \Illuminate\Support\Collection
    {
        $query = User::query()->whereIn('role', [UserRole::Agent, UserRole::Seller])->orderBy('role')->orderBy('full_name');

        if ($viewer->role === UserRole::Admin) {
            return $query->get();
        }

        if ($viewer->role === UserRole::Agent) {
            return $query->where('parent_id', $viewer->id)->get();
        }

        return collect();
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
            'ownerOptions' => $this->accountingOwnerOptions($request->user()),
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
