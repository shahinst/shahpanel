<?php

namespace Modules\ShahBot\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotAgencyRequest;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotOrder;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Services\AgencyService;
use Modules\ShahBot\Support\BotContext;

class AgentController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', BotAgencyRequest::PENDING);

        $bots = BotInstance::query()->with('owner')->latest('id')->get()->map(function (BotInstance $bot): BotInstance {
            $users = BotUser::query()->where('bot_id', $bot->id);
            $bot->setAttribute('users_count', (clone $users)->count());
            $bot->setAttribute('sales_total', (string) BotOrder::query()->whereIn('type', ['buy', 'renew'])
                ->whereIn('bot_user_id', $users->select('id'))->sum('amount'));

            return $bot;
        });

        return view('shahbot::agents', [
            'status' => $status,
            'requests' => BotAgencyRequest::query()
                ->with(['botUser', 'seller'])
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
            'bots' => $bots,
        ]);
    }

    public function approve(Request $request, BotAgencyRequest $agencyRequest, AgencyService $agency, BotContext $context): RedirectResponse
    {
        try {
            $context->run((int) $agencyRequest->botUser->bot_id, fn () => $agency->approve($agencyRequest, 'panel:'.$request->user()->username));
        } catch (InvalidArgumentException|\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('shahbot::admin.saved'));
    }

    public function reject(Request $request, BotAgencyRequest $agencyRequest, AgencyService $agency): RedirectResponse
    {
        try {
            $agency->reject($agencyRequest, 'panel:'.$request->user()->username);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('shahbot::admin.saved'));
    }

    public function toggleBot(BotInstance $bot): RedirectResponse
    {
        $bot->update(['is_active' => ! $bot->is_active]);

        return back()->with('success', __('shahbot::admin.saved'));
    }
}
