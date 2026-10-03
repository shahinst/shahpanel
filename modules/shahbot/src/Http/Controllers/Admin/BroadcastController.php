<?php

namespace Modules\ShahBot\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\ShahBot\Models\BotBroadcast;
use Modules\ShahBot\Services\BroadcastService;

class BroadcastController extends Controller
{
    public function index(BroadcastService $broadcasts): View
    {
        return view('shahbot::broadcasts', [
            'broadcasts' => BotBroadcast::query()->latest('id')->paginate(20),
            'audiences' => collect(['all', 'customers', 'no_service'])
                ->mapWithKeys(fn (string $a) => [$a => $broadcasts->audienceQuery($a)->count()]),
        ]);
    }

    public function store(Request $request, BroadcastService $broadcasts): RedirectResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:3500'],
            'audience' => ['required', 'in:all,customers,no_service'],
        ]);

        // Plain text from the form; Telegram receives it HTML-escaped so a
        // stray "<" cannot make the whole send fail.
        $broadcasts->create(e($data['text']), $data['audience'], $request->user()->username);

        return back()->with('success', __('shahbot::admin.broadcast_queued'));
    }

    public function cancel(BotBroadcast $broadcast): RedirectResponse
    {
        if (in_array($broadcast->status, [BotBroadcast::QUEUED, BotBroadcast::SENDING], true)) {
            $broadcast->update(['status' => BotBroadcast::CANCELLED, 'finished_at' => now()]);
        }

        return back()->with('success', __('shahbot::admin.saved'));
    }
}
