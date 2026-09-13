<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Account;
use App\Services\AdminAccountReportService;
use Illuminate\Http\JsonResponse;

trait ProvidesAccountReport
{
    public function report(Account $account, AdminAccountReportService $reportService): JsonResponse
    {
        $this->authorize('view', $account);

        return response()->json($reportService->build($account, request()->user()));
    }
}
