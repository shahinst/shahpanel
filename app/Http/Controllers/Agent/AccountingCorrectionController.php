<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AgentMarginCorrection;
use App\Services\AgentMarginCorrectionNoticeService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountingCorrectionController extends Controller
{
    public function index(
        Request $request,
        AgentMarginCorrectionNoticeService $noticeService,
    ): View {
        $agent = $request->user();

        abort_unless($noticeService->isMenuVisibleFor($agent), 404);

        $corrections = AgentMarginCorrection::query()
            ->where('agent_user_id', $agent->id)
            ->where('clawback_amount', '>', 0)
            ->with(['account.package'])
            ->orderByDesc('created_at')
            ->get();

        $totalClawback = $corrections->reduce(
            fn (string $carry, AgentMarginCorrection $row): string => bcadd($carry, (string) $row->clawback_amount, 2),
            '0.00',
        );

        return view('agent.accounting.corrections', [
            'corrections' => $corrections,
            'totalClawback' => $totalClawback,
            'expiresAt' => $noticeService->noticeExpiresAt(),
            'daysRemaining' => $noticeService->daysRemaining(),
        ]);
    }
}
