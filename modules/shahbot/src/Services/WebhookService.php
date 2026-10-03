<?php

namespace Modules\ShahBot\Services;

use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Support\BotContext;
use Modules\ShahBot\Support\BotSettings;
use Modules\ShahBot\Telegram\TelegramClient;

/**
 * Connects the bot to Telegram: checks the token, then registers the webhook
 * (or removes it for long polling).
 */
class WebhookService
{
    public function __construct(
        protected BotSettings $settings,
        protected TelegramClient $telegram,
    ) {}

    /**
     * Connects an agent's bot (or, with null, the main bot).
     *
     * @return array{ok: bool, message: string}
     */
    public function connectBot(?BotInstance $bot): array
    {
        return app(BotContext::class)->run($bot, function () use ($bot): array {
            $result = $this->connect();

            if ($bot !== null && $result['ok']) {
                $bot->forceFill(['username' => $this->settings->get('bot_username') ?: $bot->username])->save();
            }

            return $result;
        });
    }

    public function webhookUrl(): string
    {
        return route('shahbot.webhook', $this->settings->webhookSecret());
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function connect(): array
    {
        $me = $this->telegram->call('getMe');

        if (! ($me['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) ($me['description'] ?? 'getMe failed')];
        }

        $username = (string) ($me['result']['username'] ?? '');
        $bot = app(BotContext::class)->bot();

        if ($bot !== null) {
            $bot->forceFill(['username' => $username])->save();
        } else {
            $this->settings->set(['bot_username' => $username]);
        }

        if ($this->settings->main('mode') === 'polling') {
            $result = $this->telegram->call('deleteWebhook');

            return ['ok' => (bool) ($result['ok'] ?? false), 'message' => (string) ($result['description'] ?? '')];
        }

        $result = $this->telegram->call('setWebhook', [
            'url' => $this->webhookUrl(),
            'secret_token' => $this->settings->webhookSecret(),
            'allowed_updates' => json_encode(['message', 'callback_query', 'pre_checkout_query']),
            'max_connections' => 20,
            'drop_pending_updates' => 'true',
        ]);

        return ['ok' => (bool) ($result['ok'] ?? false), 'message' => (string) ($result['description'] ?? '')];
    }

    public function info(): array
    {
        if ($this->settings->get('bot_token') === '') {
            return [];
        }

        return $this->telegram->call('getWebhookInfo', [], 10)['result'] ?? [];
    }
}
