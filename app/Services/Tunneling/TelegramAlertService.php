<?php

namespace App\Services\Tunneling;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Direct Telegram Bot API alerts over plain HTTP (shared-hosting friendly,
 * optional proxy for networks where api.telegram.org is blocked). Alerts are
 * deduplicated by key so a flapping tunnel doesn't spam the chat.
 */
class TelegramAlertService
{
    public function send(string $message, ?string $dedupeKey = null): bool
    {
        if (! (bool) config('tunneling.telegram.enabled', false)) {
            return false;
        }

        $token = (string) config('tunneling.telegram.bot_token', '');
        $chatId = (string) config('tunneling.telegram.chat_id', '');

        if ($token === '' || $chatId === '') {
            return false;
        }

        if ($dedupeKey !== null) {
            $cacheKey = 'tunneling:tg-alert:'.sha1($dedupeKey);

            if (Cache::has($cacheKey)) {
                return false;
            }

            Cache::put($cacheKey, 1, now()->addMinutes((int) config('tunneling.telegram.dedupe_minutes', 15)));
        }

        try {
            $request = Http::timeout((int) config('tunneling.telegram.timeout_seconds', 10));

            $proxy = (string) config('tunneling.telegram.proxy', '');

            if ($proxy !== '') {
                $request = $request->withOptions(['proxy' => $proxy]);
            }

            $response = $request->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('tunneling: telegram alert failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
