<?php

namespace Modules\ShahBot\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\ShahBot\Support\BotSettings;
use RuntimeException;
use Throwable;

/**
 * Thin Bot API client. Every call goes through call(), which applies the
 * optional proxy (api.telegram.org is blocked on many hosts) and never throws
 * for an ordinary API error: the caller gets the decoded answer, ok=false
 * included, so one failed message never breaks the update being handled.
 */
class TelegramClient
{
    /** Status of the last call, so callers can tell "user blocked the bot" apart. */
    public ?int $lastErrorCode = null;

    public ?string $lastError = null;

    public function __construct(protected BotSettings $settings) {}

    protected function token(): string
    {
        $token = $this->settings->get('bot_token');

        if ($token === '') {
            throw new RuntimeException('The bot token is not set.');
        }

        return $token;
    }

    protected function request(int $timeout): PendingRequest
    {
        $request = Http::timeout($timeout)->connectTimeout(10)->acceptJson();
        $proxy = $this->settings->get('proxy');

        if ($proxy !== '') {
            $request = $request->withOptions(['proxy' => $proxy]);
        }

        return $request;
    }

    public function call(string $method, array $params = [], int $timeout = 20): array
    {
        $this->lastErrorCode = null;
        $this->lastError = null;

        try {
            $response = $this->request($timeout)->post(
                'https://api.telegram.org/bot'.$this->token().'/'.$method,
                array_filter($params, fn ($v) => $v !== null)
            );
            $data = $response->json() ?? ['ok' => false, 'description' => 'Empty response'];
        } catch (Throwable $e) {
            $data = ['ok' => false, 'error_code' => 0, 'description' => $e->getMessage()];
        }

        if (! ($data['ok'] ?? false)) {
            $this->lastErrorCode = (int) ($data['error_code'] ?? 0);
            $this->lastError = (string) ($data['description'] ?? 'unknown error');

            // "message is not modified" is the normal answer to pressing the
            // same inline button twice; it is not worth a log line.
            if (! str_contains($this->lastError, 'message is not modified')) {
                Log::info('shahbot: telegram call failed', ['method' => $method, 'error' => $this->lastError]);
            }
        }

        return $data;
    }

    public function sendMessage(int|string $chatId, string $text, ?array $keyboard = null, array $extra = []): array
    {
        return $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $keyboard !== null ? json_encode($keyboard, JSON_UNESCAPED_UNICODE) : null,
        ], $extra));
    }

    public function editMessage(int|string $chatId, int $messageId, string $text, ?array $keyboard = null): array
    {
        $result = $this->call('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $keyboard !== null ? json_encode($keyboard, JSON_UNESCAPED_UNICODE) : null,
        ]);

        // A photo message, or one too old to edit: send a fresh one instead.
        if (! ($result['ok'] ?? false) && ! str_contains((string) $this->lastError, 'message is not modified')) {
            return $this->sendMessage($chatId, $text, $keyboard);
        }

        return $result;
    }

    public function answerCallback(string $callbackId, ?string $text = null, bool $alert = false): void
    {
        $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => $alert ? 'true' : null,
        ], 10);
    }

    public function sendPhotoBytes(int|string $chatId, string $bytes, string $filename, ?string $caption = null, ?array $keyboard = null): array
    {
        try {
            $response = $this->request(30)
                ->attach('photo', $bytes, $filename)
                ->post('https://api.telegram.org/bot'.$this->token().'/sendPhoto', array_filter([
                    'chat_id' => (string) $chatId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => $keyboard !== null ? json_encode($keyboard, JSON_UNESCAPED_UNICODE) : null,
                ], fn ($v) => $v !== null));

            return $response->json() ?? ['ok' => false];
        } catch (Throwable $e) {
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }

    public function sendDocumentBytes(int|string $chatId, string $bytes, string $filename, ?string $caption = null): array
    {
        try {
            $response = $this->request(30)
                ->attach('document', $bytes, $filename)
                ->post('https://api.telegram.org/bot'.$this->token().'/sendDocument', array_filter([
                    'chat_id' => (string) $chatId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ], fn ($v) => $v !== null));

            return $response->json() ?? ['ok' => false];
        } catch (Throwable $e) {
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }

    public function sendPhotoId(int|string $chatId, string $fileId, ?string $caption = null, ?array $keyboard = null): array
    {
        return $this->call('sendPhoto', [
            'chat_id' => $chatId,
            'photo' => $fileId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'reply_markup' => $keyboard !== null ? json_encode($keyboard, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /**
     * Downloads a file the bot received (a receipt photo) so the panel can
     * show it. Returns null when Telegram cannot be reached.
     */
    public function downloadFile(string $fileId): ?string
    {
        $info = $this->call('getFile', ['file_id' => $fileId]);
        $path = $info['result']['file_path'] ?? null;

        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            $response = $this->request(30)->get('https://api.telegram.org/file/bot'.$this->token().'/'.$path);

            return $response->successful() ? $response->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function isChannelMember(string $channel, int $userId): bool
    {
        $result = $this->call('getChatMember', ['chat_id' => $channel, 'user_id' => $userId], 10);

        if (! ($result['ok'] ?? false)) {
            // The bot is not an admin of that channel, or it does not exist:
            // locking every user out over a setup mistake would be worse.
            return true;
        }

        return in_array($result['result']['status'] ?? '', ['creator', 'administrator', 'member', 'restricted'], true);
    }
}
