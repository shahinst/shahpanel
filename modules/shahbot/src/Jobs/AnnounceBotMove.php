<?php

namespace Modules\ShahBot\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotLocale;

/**
 * Tells an agent's customers, through the bot they know, that it moved.
 *
 * The customers, their services and wallets stay with the agent's bot record
 * when its token changes, but Telegram only lets the new bot write to people
 * who pressed /start in it. So the old bot, while its token still works,
 * sends everyone the new bot's link once.
 */
class AnnounceBotMove implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public int $botId,
        public string $oldTokenEnc,
        public string $newUsername,
    ) {}

    public function handle(): void
    {
        $token = Crypt::decryptString($this->oldTokenEnc);
        $link = 'https://t.me/'.ltrim($this->newUsername, '@');

        BotUser::query()
            ->where('bot_id', $this->botId)
            ->where('is_blocked', false)
            ->where('bot_blocked_by_user', false)
            ->chunkById(200, function ($users) use ($token, $link): void {
                foreach ($users as $user) {
                    $text = app(BotLocale::class)->run($user, fn () => __('shahbot::bot.bot_moved', ['link' => $link]));
                    rescue(fn () => Http::timeout(10)->asForm()->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                        'chat_id' => (string) $user->telegram_id,
                        'text' => $text,
                        'parse_mode' => 'HTML',
                    ]), report: false);
                    // Telegram allows about 30 messages a second per bot.
                    usleep(40000);
                }
            });
    }
}
