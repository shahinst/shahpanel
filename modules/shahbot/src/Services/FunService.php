<?php

namespace Modules\ShahBot\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotCode;
use Modules\ShahBot\Models\BotLottery;
use Modules\ShahBot\Models\BotOrder;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Models\BotWheelSpin;
use Modules\ShahBot\Support\BotContext;
use Modules\ShahBot\Support\BotSettings;

/**
 * The lucky wheel and lotteries. Every prize in money is paid from the bot
 * owner's wallet like other promotions; a discount prize is a single-use code
 * for the winner.
 */
class FunService
{
    public function __construct(
        protected BotSettings $settings,
        protected CodeService $codes,
        protected BotNotifier $notifier,
        protected BotContext $context,
    ) {}

    // ------------------------------------------------------------------
    // Lucky wheel
    // ------------------------------------------------------------------

    /**
     * Prize lines "label|type|value|weight"; type is wallet, discount or none.
     *
     * @return list<array{label: string, type: string, value: float, weight: int}>
     */
    public function prizes(): array
    {
        $prizes = [];

        foreach (preg_split('/\R/', $this->settings->get('wheel_prizes')) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));

            if (count($parts) < 4 || $parts[0] === '') {
                continue;
            }

            $type = in_array($parts[1], ['wallet', 'discount', 'none'], true) ? $parts[1] : 'none';
            $weight = (int) western_digits($parts[3]);

            if ($weight > 0) {
                $prizes[] = [
                    'label' => mb_substr($parts[0], 0, 120),
                    'type' => $type,
                    'value' => max(0, (float) western_digits($parts[2])),
                    'weight' => $weight,
                ];
            }
        }

        return $prizes;
    }

    public function wheelOpen(): bool
    {
        return $this->settings->bool('wheel_enabled') && $this->prizes() !== [];
    }

    /**
     * When the user may spin again, or null when they may spin now.
     */
    public function nextSpinAt(BotUser $user): ?Carbon
    {
        $last = BotWheelSpin::query()->where('bot_user_id', $user->id)->latest('id')->first();
        $hours = max(1, $this->settings->int('wheel_cooldown_hours'));

        if ($last === null || $last->created_at->addHours($hours)->isPast()) {
            return null;
        }

        return $last->created_at->addHours($hours);
    }

    public function spin(BotUser $user): BotWheelSpin
    {
        if (! $this->wheelOpen()) {
            throw new InvalidArgumentException(__('shahbot::bot.wheel_closed'));
        }

        if ($this->settings->bool('wheel_buyers_only')
            && ! BotOrder::query()->where('bot_user_id', $user->id)->whereIn('type', ['buy', 'renew'])->exists()) {
            throw new InvalidArgumentException(__('shahbot::bot.wheel_buyers_only'));
        }

        return DB::transaction(function () use ($user): BotWheelSpin {
            // Serialise spins of one user: the cooldown check and the spin
            // must not race against a double tap.
            BotUser::query()->whereKey($user->id)->lockForUpdate()->first();

            if (($next = $this->nextSpinAt($user)) !== null) {
                throw new InvalidArgumentException(__('shahbot::bot.wheel_wait', ['time' => jalali_date($next, 'Y/m/d H:i')]));
            }

            $prize = $this->pick($this->prizes());
            $code = null;

            if ($prize['type'] === 'wallet' && $prize['value'] > 0) {
                $this->codes->transfer($user, number_format($prize['value'], 2, '.', ''), 'Bot lucky wheel: '.$prize['label']);
            } elseif ($prize['type'] === 'discount' && $prize['value'] > 0) {
                $code = 'WH'.Str::upper(Str::random(8));
                BotCode::query()->create([
                    'kind' => BotCode::DISCOUNT,
                    'code' => $code,
                    'value_type' => 'percent',
                    'value' => min(100, $prize['value']),
                    'max_uses' => 1,
                    'expires_at' => now()->addDays(7),
                    'is_active' => true,
                ]);
            }

            return BotWheelSpin::query()->create([
                'bot_user_id' => $user->id,
                'prize_label' => $prize['label'],
                'prize_type' => $prize['type'],
                'prize_value' => $prize['value'],
                'code' => $code,
            ]);
        });
    }

    /**
     * @param  list<array{label: string, type: string, value: float, weight: int}>  $prizes
     * @return array{label: string, type: string, value: float, weight: int}
     */
    protected function pick(array $prizes): array
    {
        $roll = random_int(1, array_sum(array_column($prizes, 'weight')));

        foreach ($prizes as $prize) {
            $roll -= $prize['weight'];

            if ($roll <= 0) {
                return $prize;
            }
        }

        return $prizes[array_key_last($prizes)];
    }

    // ------------------------------------------------------------------
    // Lotteries
    // ------------------------------------------------------------------

    public function activeLottery(?int $botId = null): ?BotLottery
    {
        return BotLottery::query()
            ->where('bot_id', $botId ?? $this->context->botId())
            ->where('status', BotLottery::OPEN)
            ->where('starts_at', '<=', now())
            ->where('draw_at', '>', now())
            ->orderBy('draw_at')
            ->first();
    }

    /**
     * Paid orders in the lottery period: one ticket each.
     *
     * @return Collection<int, int> bot user id => tickets
     */
    public function tickets(BotLottery $lottery): Collection
    {
        return BotOrder::query()
            ->whereIn('type', ['buy', 'renew'])
            ->where('amount', '>', 0)
            ->whereBetween('created_at', [$lottery->starts_at, $lottery->draw_at])
            ->whereIn('bot_user_id', BotUser::query()->where('bot_id', $lottery->bot_id)->where('is_blocked', false)->select('id'))
            ->selectRaw('bot_user_id, COUNT(*) as n')
            ->groupBy('bot_user_id')
            ->pluck('n', 'bot_user_id')
            ->map(fn ($n) => (int) $n);
    }

    public function ticketsOf(BotLottery $lottery, BotUser $user): int
    {
        return (int) ($this->tickets($lottery)[$user->id] ?? 0);
    }

    /**
     * Draws every lottery whose time has come. Returns how many were drawn.
     */
    public function drawDue(): int
    {
        $drawn = 0;

        BotLottery::query()
            ->where('status', BotLottery::OPEN)
            ->where('draw_at', '<=', now())
            ->get()
            ->each(function (BotLottery $lottery) use (&$drawn): void {
                $this->draw($lottery);
                $drawn++;
            });

        return $drawn;
    }

    public function draw(BotLottery $lottery): BotLottery
    {
        $result = DB::transaction(function () use ($lottery): ?array {
            $locked = BotLottery::query()->whereKey($lottery->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== BotLottery::OPEN) {
                return null;
            }

            $tickets = $this->tickets($locked);
            $winners = $this->weightedWinners($tickets->all(), max(1, $locked->winners_count));
            $amount = number_format((float) $locked->prize_amount, 2, '.', '');
            $winnerUsers = BotUser::query()->whereIn('id', $winners)->get()->keyBy('id');

            foreach ($winners as $id) {
                if ((float) $amount > 0 && isset($winnerUsers[$id])) {
                    $this->codes->transfer($winnerUsers[$id], $amount, 'Bot lottery #'.$locked->id.' prize');
                }
            }

            $locked->update([
                'status' => BotLottery::DRAWN,
                'winners' => $winners,
                'participants' => $tickets->count(),
                'drawn_at' => now(),
            ]);

            return [$locked, $winnerUsers, $tickets->keys()->all()];
        });

        if ($result === null) {
            return $lottery->fresh();
        }

        [$locked, $winnerUsers, $participants] = $result;
        $names = $winnerUsers->map(fn (BotUser $u) => e($u->displayName()))->implode('، ');

        foreach (BotUser::query()->whereIn('id', $participants)->get() as $user) {
            $won = $winnerUsers->has($user->id);
            $this->notifier->user($user, __($won ? 'shahbot::bot.lottery_won' : 'shahbot::bot.lottery_lost', [
                'title' => e($locked->title),
                'amount' => format_money($locked->prize_amount),
                'winners' => $names ?: '—',
            ]));
        }

        return $locked;
    }

    /**
     * Picks distinct winners, each user's chance weighted by their tickets.
     *
     * @param  array<int, int>  $tickets
     * @return list<int>
     */
    protected function weightedWinners(array $tickets, int $count): array
    {
        $winners = [];

        while ($tickets !== [] && count($winners) < $count) {
            $roll = random_int(1, array_sum($tickets));

            foreach ($tickets as $userId => $weight) {
                $roll -= $weight;

                if ($roll <= 0) {
                    $winners[] = (int) $userId;
                    unset($tickets[$userId]);

                    break;
                }
            }
        }

        return $winners;
    }
}
