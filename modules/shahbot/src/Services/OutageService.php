<?php

namespace Modules\ShahBot\Services;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotSettings;

/**
 * Watches the servers the bot sells and makes up for their outages.
 *
 * Every few minutes each active server's API port is tried. Two failures in a
 * row mark it down (one lost packet is not an outage) from the first failure
 * on. When it answers again and was down for at least the configured minutes,
 * every active account on it gets the lost time added to its expiry, and the
 * customers who bought it in a bot are told. The same state answers the
 * customers' "server status" button, so they can see an outage before they
 * open a ticket about it.
 */
class OutageService
{
    public const FAILS_BEFORE_DOWN = 2;

    public function __construct(
        protected BotSettings $settings,
        protected BotNotifier $notifier,
    ) {}

    /**
     * @return int accounts compensated
     */
    public function run(): int
    {
        $compensated = 0;

        foreach (Server::query()->active()->get() as $server) {
            $compensated += $this->track($server, $this->reachable($server));
        }

        return $compensated;
    }

    public function track(Server $server, bool $up): int
    {
        $key = 'shahbot:outage:'.$server->id;
        $state = Cache::get($key, ['fails' => 0, 'first_fail' => null]);

        if (! $up) {
            $state['fails']++;
            $state['first_fail'] ??= now()->timestamp;
            Cache::put($key, $state, now()->addDays(7));

            return 0;
        }

        Cache::forget($key);

        if ($state['fails'] < self::FAILS_BEFORE_DOWN || $state['first_fail'] === null) {
            return 0;
        }

        $minutes = (int) round((now()->timestamp - $state['first_fail']) / 60);

        if (! $this->settings->bool('outage_comp_enabled') || $minutes < max(5, $this->settings->int('outage_comp_minutes'))) {
            return 0;
        }

        return $this->compensate($server, $minutes);
    }

    public function isDown(Server $server): bool
    {
        return (Cache::get('shahbot:outage:'.$server->id)['fails'] ?? 0) >= self::FAILS_BEFORE_DOWN;
    }

    public function compensate(Server $server, int $minutes): int
    {
        $admin = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();

        if ($admin === null) {
            return 0;
        }

        $done = 0;

        Account::query()
            ->where('server_id', $server->id)
            ->where('status', AccountStatus::Active)
            ->whereNotNull('expiry_at')
            ->whereNull('refunded_at')
            ->chunkById(200, function ($accounts) use ($admin, $minutes, &$done): void {
                $botUsers = BotUser::query()->whereIn('client_user_id', $accounts->pluck('client_user_id')->filter())->get()->groupBy('client_user_id');

                foreach ($accounts as $account) {
                    $extended = rescue(fn () => app(\App\Services\AccountService::class)
                        ->updateExpiryByAdmin($account, $account->expiry_at->copy()->addMinutes($minutes), $admin, true, false));

                    if ($extended === null) {
                        continue;
                    }

                    $done++;

                    foreach ($botUsers->get($account->client_user_id, collect()) as $user) {
                        $this->notifier->user($user, fn () => __('shahbot::bot.outage_compensated', [
                            'name' => e((string) ($account->display_label ?: $account->remote_username)),
                            'minutes' => persian_digits($minutes),
                            'date' => jalali_date($extended->expiry_at, 'Y/m/d H:i'),
                        ]));
                    }
                }
            });

        return $done;
    }

    /**
     * Customer-facing status of the servers behind the given accounts.
     *
     * @param  iterable<Account>  $accounts
     */
    public function statusText(iterable $accounts): string
    {
        $lines = [];

        foreach (collect($accounts)->pluck('server')->filter()->unique('id') as $server) {
            $lines[] = ($this->isDown($server) ? '🔴 ' : '🟢 ').e($server->name).' — '.__($this->isDown($server) ? 'shahbot::bot.server_down' : 'shahbot::bot.server_up');
        }

        return __('shahbot::bot.server_status', ['lines' => $lines === [] ? __('shahbot::bot.services_empty') : implode("\n", $lines)]);
    }

    protected function reachable(Server $server): bool
    {
        $socket = @fsockopen((string) $server->apiConnectionHost(), (int) $server->port, $errno, $error, 5);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
