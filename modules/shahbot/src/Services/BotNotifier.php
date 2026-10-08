<?php

namespace Modules\ShahBot\Services;

use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotContext;
use Modules\ShahBot\Support\BotLocale;
use Modules\ShahBot\Support\BotSettings;
use Modules\ShahBot\Support\BotTexts;
use Modules\ShahBot\Telegram\TelegramClient;

/**
 * Messages the bot sends on its own: to the admin chats (new receipt, new
 * ticket, new sale) and to users outside a conversation (approval, reply).
 */
class BotNotifier
{
    public function __construct(
        protected BotSettings $settings,
        protected TelegramClient $telegram,
        protected BotContext $context,
    ) {}

    public function admins(string $text, ?array $keyboard = null): void
    {
        app(BotTexts::class)->apply();

        foreach ($this->settings->adminChatIds() as $chatId) {
            $this->telegram->sendMessage($chatId, $text, $keyboard);
        }
    }

    /**
     * Tickets: the admins and the support operators.
     */
    public function support(string $text, ?array $keyboard = null): void
    {
        app(BotTexts::class)->apply();

        foreach (array_merge($this->settings->adminChatIds(), $this->settings->supportChatIds()) as $chatId) {
            $this->telegram->sendMessage($chatId, $text, $keyboard);
        }
    }

    public function adminsPhoto(string $fileId, string $caption, ?array $keyboard = null): void
    {
        foreach ($this->settings->adminChatIds() as $chatId) {
            $this->telegram->sendPhotoId($chatId, $fileId, $caption, $keyboard);
        }
    }

    /**
     * Messages a user through their own bot, whichever bot is in context.
     */
    /**
     * $text and $keyboard may be closures: they are then built in the user's
     * own language, which is what every message sent outside a conversation
     * (approvals, replies, reminders) should do.
     */
    public function user(BotUser $user, string|\Closure $text, array|\Closure|null $keyboard = null): bool
    {
        [$text, $keyboard] = app(BotLocale::class)->run($user, fn () => [
            $text instanceof \Closure ? $text() : $text,
            $keyboard instanceof \Closure ? $keyboard() : $keyboard,
        ]);

        $result = $this->context->botId() === (int) $user->bot_id
            ? $this->telegram->sendMessage($user->telegram_id, $text, $keyboard)
            : $this->context->run((int) $user->bot_id, fn () => $this->telegram->sendMessage($user->telegram_id, $text, $keyboard));

        if (! ($result['ok'] ?? false) && $this->telegram->lastErrorCode === 403) {
            $user->forceFill(['bot_blocked_by_user' => true])->save();
        }

        return (bool) ($result['ok'] ?? false);
    }
}
