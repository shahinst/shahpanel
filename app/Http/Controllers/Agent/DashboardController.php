<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Concerns\ProvidesStaffAccountCreateModal;
use App\Http\Controllers\Controller;
use App\Services\BroadcastBannerService;
use App\Services\DashboardStatsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    use ProvidesStaffAccountCreateModal;

    public function index(Request $request, DashboardStatsService $statsService, BroadcastBannerService $bannerService): View
    {
        return view('shared.dashboard.index', array_merge(
            [
                'stats' => $statsService->safeForAgent($request->user()),
                'broadcastBanner' => $bannerService->forUser($request->user()),
            ],
            $this->staffAccountCreateModalData($request),
        ));
    }

    protected function accountRoutePrefix(): string
    {
        return 'agent';
    }
}
