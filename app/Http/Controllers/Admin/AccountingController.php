<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HandlesAccounting;
use App\Http\Controllers\Controller;
use App\Services\AccountingExportService;
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountingController extends Controller
{
    use HandlesAccounting;

    public function index(Request $request, AccountingService $accountingService): View
    {
        return $this->accountingIndexView($request, $accountingService, 'admin.accounting.index');
    }

    public function export(
        Request $request,
        AccountingService $accountingService,
        AccountingExportService $exportService
    ): StreamedResponse {
        return $this->accountingExportResponse($request, $accountingService, $exportService);
    }
}
