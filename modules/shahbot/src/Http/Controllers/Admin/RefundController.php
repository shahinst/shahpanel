<?php

namespace Modules\ShahBot\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotRefundRequest;
use Modules\ShahBot\Services\ServiceOpsService;

class RefundController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', BotRefundRequest::PENDING);

        return view('shahbot::refunds', [
            'status' => $status,
            'requests' => BotRefundRequest::query()
                ->with(['botUser', 'account.package'])
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
        ]);
    }

    public function approve(Request $request, BotRefundRequest $refund, ServiceOpsService $ops): RedirectResponse
    {
        // A refund in a reseller's bot pays back out of that reseller's sale,
        // so it is theirs to approve -- from their bot's admin chat. The panel
        // admin only approves refunds of the main bot.
        if ((int) $refund->botUser?->bot_id !== 0) {
            return back()->with('error', __('shahbot::admin.refund_owner_only'));
        }

        try {
            $done = $ops->approveRefund($refund, 'panel:'.$request->user()->username, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('shahbot::admin.refund_done', ['amount' => format_money($done->amount)]));
    }

    public function reject(Request $request, BotRefundRequest $refund, ServiceOpsService $ops): RedirectResponse
    {
        try {
            $ops->rejectRefund($refund, 'panel:'.$request->user()->username);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('shahbot::admin.saved'));
    }
}
