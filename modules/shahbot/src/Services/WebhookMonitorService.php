<?php

namespace Modules\ShahBot\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Support\BotAccess;
use Modules\ShahBot\Support\BotContext;
use Modules\ShahBot\Support\BotSettings;
use Modules\ShahBot\Telegram\TelegramClient;

/**
 * Asks Telegram how each bot's webhook is doing and tells the people who can
 * act on it. A bot whose webhook answers 404 or times out still sends fine,
 * so nobody notices until customers complain that /start does nothing; the
 * bot itself can carry the warning to its admins.
 *
 * A webhook pointing at the wrong address is repaired on the spot (queued
 * updates kept); errors Telegram reports are only announced, because their
 * cause lies outside the panel (DNS, firewall, filter). Each problem is
 * announced once and again every few hours while it lasts, and its end is
 * announced too.
 */
class WebhookMonitorService
{
    /** Telegram keeps the last error forever; only a recent one is current. */
    public const RECENT_ERROR_SECONDS = 20 * 60;

    public const BACKLOG = 100;

    public const REPEAT_SECONDS = 6 * 3600;

    public function __construct(
        protected BotSettings $settings,
        protected TelegramClient $telegram,
        protected BotContext $context,
        protected WebhookService $webhooks,
        protected BotNotifier $notifier,
    ) {}

    /**
     * @return int problems found
     */
    public function run(): int
    {
        $problems = 0;

        foreach ($this->bots() as $bot) {
            $problems += rescue(fn () => $this->check($bot), 0) ? 1 : 0;
        }

        return $problems;
    }

    /**
     * @return Collection<int, BotInstance|null>
     */
    public function bots(): Collection
    {
        $access = app(BotAccess::class);

        return collect([null])->merge(BotInstance::query()->with('owner')->where('is_active', true)->whereNotNull('token_enc')->get()
            ->filter(fn (BotInstance $bot): bool => $access->allows($bot->owner)));
    }

    /**
     * @return bool whether the bot has a problem now
     */
    public function check(?BotInstance $bot): bool
    {
        $problem = $this->context->run($bot, function (): ?array {
            if ($this->settings->get('bot_token') === '') {
                return null;
            }

            return $this->diagnose();
        });

        $key = 'shahbot:webhook-monitor:'.($bot?->id ?? 0);
        $last = Cache::get($key);

        if ($problem === null) {
            if ($last !== null) {
                Cache::forget($key);
                $this->announce($bot, __('shahbot::admin.monitor_recovered', ['bot' => $this->name($bot)]));
            }

            return false;
        }

        $signature = $problem['kind'].'|'.$problem['detail'];

        if ($last === null || $last['signature'] !== $signature || time() - $last['at'] >= self::REPEAT_SECONDS) {
            Cache::put($key, ['signature' => $signature, 'at' => time()], now()->addDays(7));
            $this->announce($bot, $problem['message']);
        }

        return true;
    }

    /**
     * @return array{kind: string, detail: string, message: string}|null
     */
    protected function diagnose(): ?array
    {
        $info = $this->webhooks->info();
        $name = $this->name($this->context->bot());
        $url = (string) ($info['url'] ?? '');
        $polling = $this->settings->main('mode') === 'polling';

        if ($info === [] && $this->telegram->lastErrorCode === 401) {
            return ['kind' => 'token', 'detail' => '', 'message' => __('shahbot::admin.monitor_token', ['bot' => $name])];
        }

        if ($info === []) {
            return null;
        }

        // Telegram refuses getUpdates while a webhook is set.
        if ($polling) {
            if ($url === '') {
                return null;
            }

            $fixed = ($this->telegram->call('deleteWebhook')['ok'] ?? false) === true;

            return $fixed ? null : ['kind' => 'polling', 'detail' => $url, 'message' => __('shahbot::admin.monitor_polling', ['bot' => $name])];
        }

        $expected = $this->webhooks->webhookUrl();

        if ($url !== $expected) {
            $fixed = ($this->webhooks->registerWebhook(false)['ok'] ?? false) === true;

            if ($fixed) {
                $this->announce($this->context->bot(), __('shahbot::admin.monitor_repaired', ['bot' => $name, 'url' => $expected]));

                return null;
            }

            return ['kind' => 'url', 'detail' => $url, 'message' => __('shahbot::admin.monitor_url', ['bot' => $name, 'url' => $expected])];
        }

        $errorAt = (int) ($info['last_error_date'] ?? 0);
        $error = trim((string) ($info['last_error_message'] ?? ''));

        if ($error !== '' && $errorAt >= time() - self::RECENT_ERROR_SECONDS) {
            $hint = WebhookService::webhookUnreachable($info) ? "\n\n".__('shahbot::admin.webhook_unreachable_hint') : '';

            return ['kind' => 'error', 'detail' => $error, 'message' => __('shahbot::admin.monitor_error', [
                'bot' => $name,
                'error' => $error,
                'pending' => (int) ($info['pending_update_count'] ?? 0),
            ]).$hint];
        }

        $pending = (int) ($info['pending_update_count'] ?? 0);

        if ($pending >= self::BACKLOG) {
            return ['kind' => 'backlog', 'detail' => '', 'message' => __('shahbot::admin.monitor_backlog', ['bot' => $name, 'pending' => $pending])];
        }

        return null;
    }

    /**
     * The bot's own admins hear it through that bot; for an agent's bot the
     * main bot's admins hear it too, since agents rarely fix servers.
     */
    protected function announce(?BotInstance $bot, string $text): void
    {
        $this->context->run($bot, fn () => rescue(fn () => $this->notifier->admins($text)));

        if ($bot !== null) {
            $this->context->run(null, fn () => rescue(fn () => $this->settings->get('bot_token') !== '' ? $this->notifier->admins($text) : null));
        }
    }

    protected function name(?BotInstance $bot): string
    {
        $username = $bot !== null ? (string) $bot->username : (string) $this->settings->main('bot_username');

        return $username !== '' ? '@'.$username : '#'.($bot?->id ?? 0);
    }
}
