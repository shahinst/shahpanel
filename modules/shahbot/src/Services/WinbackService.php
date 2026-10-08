<?php

namespace Modules\ShahBot\Services;

use App\Enums\AccountStatus;
use App\Models\Account;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\ShahBot\Models\BotCode;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotSettings;
use Modules\ShahBot\Telegram\Keyboard;

/**
 * Offers a customer who let their service run out a one-time discount code a
 * few days later, while they may still come back.
 *
 * Only the main bot's customers get it: a discount is funded by the bot's
 * owner, and an agent never agreed to pay for an offer the admin switched on.
 * A customer is offered once per lapse (their latest expiry), only while they
 * have nothing active and only for a lapse of the last 30 days, so turning
 * the feature on does not message everyone who ever left.
 */
class WinbackService
{
    public const LOOKBACK_DAYS = 30;

    public function __construct(
        protected BotSettings $settings,
        protected BotNotifier $notifier,
    ) {}

    public function run(): int
    {
        if (! $this->settings->bool('winback_enabled') || ! $this->settings->isConfigured()) {
            return 0;
        }

        $days = max(1, $this->settings->int('winback_days'));
        $sent = 0;

        BotUser::query()
            ->where('bot_id', 0)
            ->whereNotNull('client_user_id')
            ->where('is_blocked', false)
            ->where('bot_blocked_by_user', false)
            ->chunkById(200, function ($users) use ($days, &$sent): void {
                $accounts = Account::query()
                    ->whereIn('client_user_id', $users->pluck('client_user_id'))
                    ->whereNull('refunded_at')
                    ->get()
                    ->groupBy('client_user_id');

                foreach ($users as $user) {
                    $sent += $this->offer($user, $accounts->get($user->client_user_id, collect()), $days);
                }
            });

        return $sent;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Account>  $accounts
     */
    protected function offer(BotUser $user, $accounts, int $days): int
    {
        if ($accounts->isEmpty() || $accounts->contains(fn (Account $a): bool => $a->status === AccountStatus::Active && ($a->expiry_at === null || $a->expiry_at->isFuture()))) {
            return 0;
        }

        $last = $accounts->filter(fn (Account $a): bool => $a->expiry_at !== null)->sortByDesc('expiry_at')->first();

        if ($last === null || $last->expiry_at->gt(now()->subDays($days)) || $last->expiry_at->lt(now()->subDays(self::LOOKBACK_DAYS))) {
            return 0;
        }

        // Trials end too, but nobody paid for them: that is not a lapse.
        if ($last->packageDuration?->tier->isTest()) {
            return 0;
        }

        if (! Cache::add('shahbot:winback:'.$user->id.':'.$last->expiry_at->timestamp, 1, now()->addDays(self::LOOKBACK_DAYS + 7))) {
            return 0;
        }

        $percent = max(1, min(90, $this->settings->int('winback_percent')));
        $validDays = max(1, min(60, $this->settings->int('winback_valid_days')));
        $code = BotCode::query()->create([
            'kind' => BotCode::DISCOUNT,
            'code' => 'BACK'.Str::upper(Str::random(8)),
            'value_type' => 'percent',
            'value' => $percent,
            'max_uses' => 1,
            'expires_at' => now()->addDays($validDays),
            'is_active' => true,
        ]);

        $keyboard = $this->settings->bool('renew_enabled')
            ? fn () => Keyboard::inline([[Keyboard::button(__('shahbot::bot.btn_renew'), 'svc:rn:'.$last->id)]])
            : null;

        $this->notifier->user($user, fn () => __('shahbot::bot.winback_offer', [
            'name' => e((string) ($last->display_label ?: $last->remote_username)),
            'percent' => persian_digits($percent),
            'code' => $code->code,
            'date' => jalali_date($code->expires_at, 'Y/m/d'),
        ]), $keyboard);

        return 1;
    }
}
