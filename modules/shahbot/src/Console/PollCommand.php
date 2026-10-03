<?php

namespace Modules\ShahBot\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Modules\ShahBot\Bot\UpdateHandler;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Support\BotAccess;
use Modules\ShahBot\Support\BotContext;
use Modules\ShahBot\Support\BotSettings;
use Modules\ShahBot\Telegram\TelegramClient;
use Throwable;

/**
 * Long polling, for panels Telegram cannot reach (no valid HTTPS, or a
 * firewall in front). The scheduler starts it every minute and it listens for
 * just under a minute, so there is always exactly one listener.
 */
class PollCommand extends Command
{
    protected $signature = 'shahbot:poll {--seconds=55 : How long to keep listening}';

    protected $description = 'Receive Telegram bot updates by long polling (when the webhook mode is off)';

    public function handle(BotSettings $settings, BotContext $context, TelegramClient $telegram, UpdateHandler $handler): int
    {
        if ($settings->main('mode') !== 'polling') {
            return self::SUCCESS;
        }

        // The main bot and every active agent bot that has a token.
        $bots = collect([null]);

        // Only bots whose owner still holds access: revoking it stops the bot.
        $access = app(BotAccess::class);
        $bots = $bots->merge(BotInstance::query()->with('owner')->where('is_active', true)->whereNotNull('token_enc')->get()
            ->filter(fn (BotInstance $bot): bool => $access->allows($bot->owner)));

        $until = time() + max(5, (int) $this->option('seconds'));
        $single = $bots->count() === 1;

        while (time() < $until) {
            foreach ($bots as $bot) {
                $context->run($bot, function () use ($settings, $telegram, $handler, $single, $until, $bot): void {
                    if ($settings->get('bot_token') === '') {
                        return;
                    }

                    $key = 'shahbot:poll:offset:'.($bot?->id ?? 0);
                    // One bot can wait on Telegram; several take turns without waiting.
                    $wait = $single ? max(1, min(25, $until - time() - 2)) : 0;
                    $result = $telegram->call('getUpdates', [
                        'offset' => (int) Cache::get($key, 0),
                        'timeout' => $wait,
                        'allowed_updates' => json_encode(['message', 'callback_query', 'pre_checkout_query']),
                    ], $wait + 10);

                    foreach ($result['result'] ?? [] as $update) {
                        Cache::forever($key, (int) $update['update_id'] + 1);

                        try {
                            $handler->handle($update);
                        } catch (Throwable $e) {
                            report($e);
                        }
                    }
                });
            }

            if (! $single) {
                sleep(1);
            }
        }

        return self::SUCCESS;
    }
}
