<?php

namespace Modules\ShahBot\Services;

use App\Enums\AccountStatus;
use App\Models\Account;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotSettings;
use Modules\ShahBot\Telegram\Keyboard;

/**
 * Tells bot users, in the bot, that a service is about to expire or run out of
 * data. Each warning goes out once per account and expiry date.
 */
class ReminderService
{
    public function __construct(
        protected BotSettings $settings,
        protected BotNotifier $notifier,
    ) {}

    public function run(): int
    {
        if (! $this->settings->bool('reminder_enabled') || ! $this->settings->isConfigured()) {
            return 0;
        }

        App::setLocale('fa');
        $days = max(1, $this->settings->int('reminder_days'));
        $percent = max(0, min(90, $this->settings->int('low_traffic_percent')));
        $sent = 0;

        BotUser::query()
            ->whereNotNull('client_user_id')
            ->where('is_blocked', false)
            ->where('bot_blocked_by_user', false)
            ->chunkById(200, function ($users) use ($days, $percent, &$sent): void {
                $accounts = Account::query()
                    ->whereIn('client_user_id', $users->pluck('client_user_id'))
                    ->where('status', AccountStatus::Active)
                    ->whereNull('refunded_at')
                    ->get()
                    ->groupBy('client_user_id');

                foreach ($users as $user) {
                    foreach ($accounts->get($user->client_user_id, collect()) as $account) {
                        $sent += $this->remindExpiry($user, $account, $days);
                        $sent += $this->remindTraffic($user, $account, $percent);
                    }
                }
            });

        return $sent;
    }

    protected function remindExpiry(BotUser $user, Account $account, int $days): int
    {
        if ($account->expiry_at === null || $account->expiry_at->isPast() || $account->expiry_at->gt(now()->addDays($days))) {
            return 0;
        }

        $key = 'shahbot:remind:exp:'.$account->id.':'.$account->expiry_at->timestamp;

        if (! Cache::add($key, 1, now()->addDays($days + 2))) {
            return 0;
        }

        $this->notifier->user($user, __('shahbot::bot.remind_expiry', [
            'name' => e((string) ($account->display_label ?: $account->remote_username)),
            'days' => persian_digits(max(0, (int) ceil(now()->diffInHours($account->expiry_at) / 24))),
            'date' => jalali_date($account->expiry_at, 'Y/m/d H:i'),
        ]), Keyboard::inline([[Keyboard::button(__('shahbot::bot.btn_renew'), 'svc:rn:'.$account->id)]]));

        return 1;
    }

    protected function remindTraffic(BotUser $user, Account $account, int $percent): int
    {
        $limit = (int) $account->data_limit_bytes;

        if ($percent <= 0 || $limit <= 0) {
            return 0;
        }

        $left = max(0, $limit - (int) $account->data_used_bytes);

        if ($left * 100 > $limit * $percent) {
            return 0;
        }

        $key = 'shahbot:remind:gb:'.$account->id.':'.$limit.':'.optional($account->expiry_at)->timestamp;

        if (! Cache::add($key, 1, now()->addDays(30))) {
            return 0;
        }

        $this->notifier->user($user, __('shahbot::bot.remind_traffic', [
            'name' => e((string) ($account->display_label ?: $account->remote_username)),
            'percent' => persian_digits((int) floor($left * 100 / $limit)),
            'left' => persian_digits(format_data_size($left)),
        ]), Keyboard::inline([[Keyboard::button(__('shahbot::bot.btn_renew'), 'svc:rn:'.$account->id)]]));

        return 1;
    }
}
