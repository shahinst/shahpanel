<?php

namespace Modules\ShahBot\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotLottery;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Models\BotWheelSpin;
use Modules\ShahBot\Services\FunService;

class LotteryController extends Controller
{
    public function index(FunService $fun): View
    {
        $lotteries = BotLottery::query()->latest('id')->paginate(20);
        $winnerIds = $lotteries->getCollection()->flatMap(fn (BotLottery $l) => (array) $l->winners)->unique()->all();

        return view('shahbot::lotteries', [
            'lotteries' => $lotteries,
            'winners' => BotUser::query()->whereIn('id', $winnerIds ?: [0])->get()->keyBy('id'),
            'bots' => BotInstance::query()->with('owner')->get(),
            'ticketCounts' => $lotteries->getCollection()
                ->filter(fn (BotLottery $l) => $l->status === BotLottery::OPEN)
                ->mapWithKeys(fn (BotLottery $l) => [$l->id => $fun->tickets($l)->sum()]),
            'spins' => BotWheelSpin::query()->with('botUser')->latest('id')->limit(15)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bot_id' => ['nullable', 'integer', 'min:0'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'prize_amount' => ['required', 'numeric', 'min:0'],
            'winners_count' => ['required', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['required', 'string'],
            'draw_at' => ['required', 'string'],
        ]);

        $starts = parse_jalali_date($data['starts_at']);
        $draw = parse_jalali_date($data['draw_at'], true);

        if ($starts === null || $draw === null || $draw->lte($starts)) {
            return back()->withErrors(['draw_at' => __('shahbot::admin.lottery_dates_invalid')])->withInput();
        }

        BotLottery::query()->create([
            'bot_id' => (int) ($data['bot_id'] ?? 0),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'prize_amount' => $data['prize_amount'],
            'winners_count' => $data['winners_count'],
            'starts_at' => $starts,
            'draw_at' => $draw,
            'status' => BotLottery::OPEN,
        ]);

        return back()->with('success', __('shahbot::admin.saved'));
    }

    public function cancel(BotLottery $lottery): RedirectResponse
    {
        BotLottery::query()->whereKey($lottery->id)->where('status', BotLottery::OPEN)->update(['status' => BotLottery::CANCELLED]);

        return back()->with('success', __('shahbot::admin.saved'));
    }

    public function draw(BotLottery $lottery, FunService $fun): RedirectResponse
    {
        $fun->draw($lottery);

        return back()->with('success', __('shahbot::admin.lottery_drawn'));
    }
}
