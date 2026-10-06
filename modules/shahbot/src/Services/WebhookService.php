<?php

namespace Modules\ShahBot\Services;

use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Support\BotContext;
use Modules\ShahBot\Support\BotSettings;
use Modules\ShahBot\Support\BotTexts;
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

    /**
     * Built on the panel's own address (APP_URL), not on the host the admin or
     * agent happened to open the panel on: a second domain, the bare IP or a
     * CDN name would hand Telegram an address that answers 404.
     */
    public function webhookUrl(): string
    {
        $path = route('shahbot.webhook', $this->settings->webhookSecret(), false);
        $base = rtrim((string) config('app.url'), '/');

        // Telegram only takes https; an http APP_URL is a panel without SSL.
        return str_starts_with($base, 'https://') ? $base.$path : url($path);
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

        $this->setMenuButton();

        if ($this->settings->main('mode') === 'polling') {
            $result = $this->telegram->call('deleteWebhook');

            if (! ($result['ok'] ?? false)) {
                return ['ok' => false, 'message' => (string) ($result['description'] ?? '')];
            }

            return $this->sendTestMessage($username);
        }

        $result = $this->telegram->call('setWebhook', [
            'url' => $this->webhookUrl(),
            'secret_token' => $this->settings->webhookSecret(),
            'allowed_updates' => json_encode(['message', 'callback_query', 'pre_checkout_query']),
            'max_connections' => 20,
            'drop_pending_updates' => 'true',
        ]);

        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) ($result['description'] ?? '')];
        }

        return $this->sendTestMessage($username);
    }

    /**
     * Proves the connection end to end by messaging the bot's admins.
     *
     * getMe and setWebhook both succeed while the bot is still useless: they
     * only show the panel can reach Telegram, not that the admin hears from
     * the bot. A message in the admin's own chat is the one check nobody has
     * to interpret. Telegram refuses it until that admin has pressed /start
     * once, so that case is named rather than shown as a bare "chat not found".
     *
     * @return array{ok: bool, message: string}
     */
    public function sendTestMessage(string $username = ''): array
    {
        $chats = $this->settings->adminChatIds();

        if ($chats === []) {
            return ['ok' => false, 'message' => __('shahbot::admin.test_no_admins')];
        }

        $sent = 0;
        $errors = [];

        foreach ($chats as $chat) {
            $reply = $this->telegram->sendMessage($chat, __('shahbot::admin.test_message', [
                'bot' => $username !== '' ? '@'.$username : config('app.name'),
                'time' => now()->format('Y-m-d H:i:s'),
            ]));

            if ($reply['ok'] ?? false) {
                $sent++;

                continue;
            }

            $error = (string) ($reply['description'] ?? 'unknown error');
            $errors[] = $chat.': '.(str_contains(strtolower($error), 'chat not found')
                ? __('shahbot::admin.test_start_first')
                : $error);
        }

        if ($sent === 0) {
            return ['ok' => false, 'message' => __('shahbot::admin.test_failed', ['errors' => implode(' | ', $errors)])];
        }

        $message = __('shahbot::admin.test_sent', ['count' => $sent]);

        return ['ok' => true, 'message' => $errors === [] ? $message : $message.' '.__('shahbot::admin.test_partial', ['errors' => implode(' | ', $errors)])];
    }

    /**
     * Telegram's side of the webhook says it cannot open a connection to this
     * server: the panel is behind a filter or a firewall Telegram cannot pass.
     */
    public static function webhookUnreachable(array $info): bool
    {
        $error = strtolower((string) ($info['last_error_message'] ?? ''));

        return $error !== '' && preg_match('/timed out|timeout|connection refused|no route|network is unreachable|connection reset/', $error) === 1;
    }

    public function info(): array
    {
        if ($this->settings->get('bot_token') === '') {
            return [];
        }

        return $this->telegram->call('getWebhookInfo', [], 10)['result'] ?? [];
    }

    /**
     * Points the bot's menu button at the mini app when it can be served.
     */
    protected function setMenuButton(): void
    {
        if (! $this->settings->bool('mini_app_enabled') || ! str_starts_with((string) config('app.url'), 'https://')) {
            return;
        }

        app(BotTexts::class)->apply();
        $this->telegram->call('setChatMenuButton', [
            'menu_button' => json_encode([
                'type' => 'web_app',
                'text' => strip_tags(__('shahbot::bot.menu_app', [], 'fa')),
                'web_app' => ['url' => route('shahbot.app', ['bot' => app(BotContext::class)->botId()])],
            ], JSON_UNESCAPED_UNICODE),
        ], 10);
    }
}
