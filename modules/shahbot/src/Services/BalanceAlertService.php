<?php

namespace Modules\ShahBot\Services;

use App\Models\User;
use App\Services\WalletService;
use Illuminate\Support\Facades\Cache;
use Modules\ShahBot\Support\BotContext;
use Modules\ShahBot\Support\BotSettings;

/**
 * Warns a bot's owner, through their own bot, when their wallet runs low.
 * Every sale in the bot is paid from that wallet, so an empty one stops the
 * bot selling while customers wait. One warning per dip: it comes again only
 * after the balance has been topped up above the line and fallen again.
 */
class BalanceAlertService
{
    public function __construct(
        protected BotSettings $settings,
        protected BotContext $context,
        protected BotNotifier $notifier,
    ) {}

    public function run(): int
    {
        $threshold = (float) $this->settings->main('low_balance_alert');

        if ($threshold <= 0) {
            return 0;
        }

        $sent = 0;

        foreach (app(WebhookMonitorService::class)->bots() as $bot) {
            $sent += (int) $this->context->run($bot, fn (): bool => $this->check($threshold));
        }

        return $sent;
    }

    public function balance(User $owner): string
    {
        return (string) app(WalletService::class)->getOrCreateWallet($owner)->balance;
    }

    protected function check(float $threshold): bool
    {
        if (! $this->settings->isConfigured()) {
            return false;
        }

        $owner = User::query()->find((int) $this->settings->get('owner_user_id'));

        if ($owner === null) {
            return false;
        }

        $key = 'shahbot:low-balance:'.$owner->id;
        $balance = $this->balance($owner);

        if ((float) $balance >= $threshold) {
            Cache::forget($key);

            return false;
        }

        if (! Cache::add($key, 1, now()->addDays(30))) {
            return false;
        }

        $this->notifier->admins(__('shahbot::bot.low_balance_alert', ['balance' => format_money($balance), 'threshold' => format_money($threshold)]));

        return true;
    }
}
