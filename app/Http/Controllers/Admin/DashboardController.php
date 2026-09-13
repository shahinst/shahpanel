<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminSystemHealthService;
use App\Services\DashboardStatsService;
use App\Services\ServerResourceMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(DashboardStatsService $statsService): View
    {
        return view('shared.dashboard.index', [
            'stats' => $statsService->safeForAdmin(),
        ]);
    }

    public function systemHealth(AdminSystemHealthService $healthService): JsonResponse
    {
        return response()->json($healthService->snapshot());
    }

    public function serverStats(ServerResourceMonitorService $monitor): JsonResponse
    {
        $budget = max(15, (int) config('vpnpanel.server_monitor.fetch_timeout_seconds', 12) + 5);
        set_time_limit($budget);

        return response()->json($monitor->dashboardPayload());
    }
}
