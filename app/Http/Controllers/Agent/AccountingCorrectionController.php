<?php

namespace App\Http\Controllers\Agent;

use App\Enums\MoneyCurrency;
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

        // جدول agent_margin_corrections ستون ارز ندارد؛ ارز هر ردیف از بسته‌ی اکانت مربوطه خوانده می‌شود
        // و اگر بسته حذف شده باشد ارز پیش‌فرض پنل ملاک است. جمع‌ها به تفکیک ارز نگه داشته می‌شوند.
        $totalClawback = $corrections->reduce(
            function (array $carry, AgentMarginCorrection $row): array {
                $code = ($row->account?->package?->moneyCurrency() ?? MoneyCurrency::default())->value;
                $carry[$code] = bcadd($carry[$code] ?? '0.00', (string) $row->clawback_amount, 2);

                return $carry;
            },
            [],
        );

        return view('agent.accounting.corrections', [
            'corrections' => $corrections,
            'totalClawback' => $totalClawback,
            'expiresAt' => $noticeService->noticeExpiresAt(),
            'daysRemaining' => $noticeService->daysRemaining(),
        ]);
    }
}
