<?php

namespace Modules\ShahBot\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Modules\ShahBot\Bot\UpdateHandler;
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

    public function handle(BotSettings $settings, TelegramClient $telegram, UpdateHandler $handler): int
    {
        if ($settings->get('mode') !== 'polling' || $settings->get('bot_token') === '') {
            return self::SUCCESS;
        }

        $until = time() + max(5, (int) $this->option('seconds'));
        $offset = (int) Cache::get('shahbot:poll:offset', 0);

        while (time() < $until) {
            $wait = max(1, min(25, $until - time() - 2));
            $result = $telegram->call('getUpdates', [
                'offset' => $offset,
                'timeout' => $wait,
                'allowed_updates' => json_encode(['message', 'callback_query']),
            ], $wait + 10);

            if (! ($result['ok'] ?? false)) {
                sleep(3);

                continue;
            }

            foreach ($result['result'] ?? [] as $update) {
                $offset = (int) $update['update_id'] + 1;
                Cache::forever('shahbot:poll:offset', $offset);

                try {
                    $handler->handle($update);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return self::SUCCESS;
    }
}
