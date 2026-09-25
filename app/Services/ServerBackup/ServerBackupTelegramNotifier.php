<?php

namespace App\Services\ServerBackup;

use App\Support\ServerBackupTelegramSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers finished server backups to the admin's Telegram chat.
 *
 * The status report is always sent as its own sendMessage *before* the
 * documents: a zip Telegram refuses (over the 50 MB bot limit) or a server whose
 * backup threw must still produce a readable report, and a caption attached to a
 * document would disappear together with the document.
 */
class ServerBackupTelegramNotifier
{
    private const API_BASE = 'https://api.telegram.org/bot';

    /**
     * Telegram's own hard limit for documents uploaded by a bot. Anything above
     * it is rejected no matter how long we wait, so it is checked locally
     * instead of being discovered after a 50 MB upload.
     */
    public function maxDocumentBytes(): int
    {
        return max(1, (int) config('shahpanel.server_backup.telegram.max_document_bytes', 50 * 1024 * 1024));
    }

    /**
     * One scheduled slot. Servers sharing the same time land in the same call
     * and therefore in a single report; different times arrive in different
     * minutes and so produce separate reports.
     *
     * @param  list<array{
     *     server_id: int,
     *     server_name: string,
     *     server_type: string,
     *     ok: bool,
     *     error: ?string,
     *     contents: list<string>,
     *     bytes: int,
     *     zip_path: ?string,
     *     zip_filename: ?string,
     *     too_large: bool
     * }>  $results
     */
    public function sendBatch(string $slot, array $results): bool
    {
        if ($results === []) {
            return false;
        }

        $sent = $this->sendMessage($this->buildReport($slot, $results));

        $undelivered = [];

        foreach ($results as $result) {
            $path = (string) ($result['zip_path'] ?? '');

            if (! $result['ok'] || $result['too_large'] || $path === '' || ! is_file($path)) {
                continue;
            }

            $delivered = $this->sendDocument(
                $path,
                (string) ($result['zip_filename'] ?? basename($path)),
                $this->buildCaption($result),
            );

            if (! $delivered) {
                $undelivered[] = (string) $result['server_name'];
            }
        }

        // آپلود ناموفق نباید بی‌صدا بماند؛ گزارش پیش از آپلود رفته و خبر ندارد
        // فایل نرسیده، پس یک پیام کوتاهِ پیگیری فرستاده می‌شود.
        if ($undelivered !== []) {
            $this->sendMessage('⚠️ '.e(__('server_backups.tg_upload_failed', [
                'servers' => implode(' | ', $undelivered),
            ])));
        }

        return $sent;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function sendTest(): array
    {
        if (! ServerBackupTelegramSettings::isConfigured()) {
            return ['ok' => false, 'message' => __('server_backups.telegram_not_configured')];
        }

        $text = '🤖 '.e(__('server_backups.tg_test_message'))."\n"
            .'📅 '.e($this->stamp())."\n"
            .'🕒 '.e((string) config('app.timezone'));

        $response = $this->post('sendMessage', [
            'chat_id' => (string) ServerBackupTelegramSettings::chatId(),
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        if ($this->ok($response)) {
            return ['ok' => true, 'message' => __('server_backups.telegram_test_sent')];
        }

        return [
            'ok' => false,
            'message' => __('server_backups.telegram_test_failed', [
                'message' => $this->describeFailure($response),
            ]),
        ];
    }

    public function sendMessage(string $text): bool
    {
        $response = $this->post('sendMessage', [
            'chat_id' => (string) ServerBackupTelegramSettings::chatId(),
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], 2);

        return $this->ok($response);
    }

    public function sendDocument(string $path, string $filename, string $caption): bool
    {
        $token = ServerBackupTelegramSettings::botToken();
        $chatId = ServerBackupTelegramSettings::chatId();

        if ($token === null || $chatId === null || ! is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $response = $this->client((int) config('shahpanel.server_backup.telegram.upload_timeout_seconds', 300))
                ->attach('document', $handle, $filename)
                ->post(self::API_BASE.$token.'/sendDocument', [
                    'chat_id' => $chatId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]);

            if (! $this->ok($response)) {
                $this->logFailure('sendDocument', $this->describeFailure($response));

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            // یک قطعی تلگرام نباید زمان‌بند را بخواباند؛ بک‌آپ روی دیسک هست.
            $this->logFailure('sendDocument', $exception->getMessage());

            return false;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Telegram hashtags may only contain [A-Za-z0-9_] and need at least one
     * letter, so a fully Persian/Chinese server name cannot become one and falls
     * back to the row id.
     */
    public function hashtag(int $serverId, string $serverName): string
    {
        $slug = (string) preg_replace('/[^A-Za-z0-9_]+/u', '_', $serverName);
        $slug = (string) preg_replace('/_{2,}/', '_', $slug);
        $slug = trim($slug, '_');

        if ($slug === '' || preg_match('/[A-Za-z]/', $slug) !== 1) {
            return '#server_'.$serverId;
        }

        return '#'.substr($slug, 0, 60);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    protected function buildReport(string $slot, array $results): string
    {
        $succeeded = count(array_filter($results, static fn (array $row): bool => (bool) ($row['ok'] ?? false)));
        $total = count($results);

        if ($succeeded === $total) {
            $title = '✅ '.__('server_backups.tg_title_success');
        } elseif ($succeeded === 0) {
            $title = '❌ '.__('server_backups.tg_title_failed');
        } else {
            $title = '⚠️ '.__('server_backups.tg_title_partial');
        }

        $lines = [
            '<b>'.e($title).'</b>',
            '',
            '🕒 '.e(__('server_backups.tg_line_slot', [
                'time' => persian_digits($slot),
                'timezone' => (string) config('app.timezone'),
            ])),
            '📅 '.e(__('server_backups.tg_line_datetime', ['datetime' => $this->stamp()])),
            '🖥 '.e(__('server_backups.tg_line_server_count', [
                'done' => persian_digits((string) $succeeded),
                'total' => persian_digits((string) $total),
            ])),
        ];

        foreach ($results as $result) {
            $lines[] = '➖➖➖➖➖➖➖➖';

            foreach ($this->serverLines($result) as $line) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    protected function serverLines(array $result): array
    {
        $serverId = (int) ($result['server_id'] ?? 0);
        $name = (string) ($result['server_name'] ?? '');
        $ok = (bool) ($result['ok'] ?? false);

        $lines = [
            ($ok ? '✅' : '❌').' '.e(__('server_backups.tg_line_server', ['name' => $name])),
            '🏷 '.e($this->hashtag($serverId, $name)),
            '🔖 '.e(__('server_backups.tg_line_type', ['type' => (string) ($result['server_type'] ?? '')])),
        ];

        if (! $ok) {
            $reason = $this->trim((string) ($result['error'] ?? ''));

            $lines[] = '⚠️ '.e(__('server_backups.tg_line_error', [
                'message' => $reason !== '' ? $reason : __('server_backups.tg_unknown_error'),
            ]));

            return $lines;
        }

        $lines[] = '📦 '.e(__('server_backups.tg_line_contents', [
            'items' => $this->contentsLabel(is_array($result['contents'] ?? null) ? $result['contents'] : []),
        ]));
        $lines[] = '💾 '.e(__('server_backups.tg_line_size', [
            'size' => format_data_size((int) ($result['bytes'] ?? 0)),
        ]));

        if ((bool) ($result['too_large'] ?? false)) {
            $lines[] = '🚫 '.e(__('server_backups.tg_line_too_large', [
                'limit' => format_data_size($this->maxDocumentBytes()),
                'path' => (string) ($result['zip_path'] ?? ''),
            ]));

            return $lines;
        }

        $lines[] = '📎 '.e((string) ($result['zip_filename'] ?? ''));

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function buildCaption(array $result): string
    {
        $name = (string) ($result['server_name'] ?? '');

        return '📦 '.e($name)."\n"
            .'🏷 '.e($this->hashtag((int) ($result['server_id'] ?? 0), $name))."\n"
            .'💾 '.e(format_data_size((int) ($result['bytes'] ?? 0)))."\n"
            .'📅 '.e($this->stamp());
    }

    /**
     * @param  list<string>  $contents
     */
    protected function contentsLabel(array $contents): string
    {
        $contents = array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $contents,
        ), static fn (string $item): bool => $item !== ''));

        if ($contents === []) {
            return __('server_backups.tg_contents_none');
        }

        $shown = array_slice($contents, 0, 6);
        $label = implode(', ', $shown);

        if (count($contents) > count($shown)) {
            $label .= ' '.__('server_backups.tg_contents_more', [
                'count' => persian_digits((string) (count($contents) - count($shown))),
            ]);
        }

        return $label;
    }

    /**
     * Jalali for the Persian panel, Gregorian otherwise — jalali_date() already
     * branches on the active locale. There is no request in cron, so the locale
     * is APP_LOCALE.
     */
    protected function stamp(): string
    {
        return jalali_date(now(), 'Y/m/d H:i:s');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function post(string $method, array $payload, int $retryTimes = 0): ?Response
    {
        $token = ServerBackupTelegramSettings::botToken();

        if ($token === null || (string) ($payload['chat_id'] ?? '') === '') {
            return null;
        }

        try {
            $request = $this->client((int) config('shahpanel.server_backup.telegram.timeout_seconds', 20));

            if ($retryTimes > 0) {
                // متن قابل ارسال مجدد است (برخلاف استریم فایل)، پس فقط این‌جا retry.
                $request = $request->retry($retryTimes + 1, 750, null, false);
            }

            $response = $request->post(self::API_BASE.$token.'/'.$method, $payload);

            if (! $this->ok($response)) {
                $this->logFailure($method, $this->describeFailure($response));
            }

            return $response;
        } catch (Throwable $exception) {
            $this->logFailure($method, $exception->getMessage());

            return null;
        }
    }

    protected function client(int $timeout): PendingRequest
    {
        $request = Http::timeout(max(5, $timeout))
            ->connectTimeout(max(3, (int) config('shahpanel.server_backup.telegram.connect_timeout_seconds', 10)));

        $proxy = trim((string) config('shahpanel.server_backup.telegram.proxy', ''));

        if ($proxy !== '') {
            $request = $request->withOptions(['proxy' => $proxy]);
        }

        return $request;
    }

    protected function ok(?Response $response): bool
    {
        return $response !== null && $response->successful() && $response->json('ok') === true;
    }

    protected function describeFailure(?Response $response): string
    {
        if ($response === null) {
            return __('server_backups.tg_send_failed');
        }

        $description = $response->json('description');

        if (is_string($description) && trim($description) !== '') {
            return $this->trim($description);
        }

        return 'HTTP '.$response->status();
    }

    /** The bot token never reaches the log — only the API method and the reason. */
    protected function logFailure(string $method, string $reason): void
    {
        Log::warning('server_backup.telegram_failed', [
            'method' => $method,
            'reason' => $this->trim($reason),
        ]);
    }

    protected function trim(string $value, int $limit = 300): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit).'…' : $value;
    }
}
