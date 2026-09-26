<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\ServerBackup;
use App\Services\ServerBackup\ServerBackupArchiver;
use App\Services\ServerBackup\ServerBackupService;
use App\Services\ServerBackup\ServerBackupTelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Takes a backup of every server due at one scheduled time and delivers the
 * zips to Telegram in a single report. One job per scheduled minute, which is
 * what makes "same time → one message, different times → separate messages"
 * true without any grouping logic of its own.
 */
class SendServerBackupToTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * تلاش مجدد یعنی بک‌آپ دوباره از روتر و پیام تکراری برای ادمین؛ خطاها
     * به‌جای retry داخل همین اجرا گزارش می‌شوند.
     */
    public int $tries = 1;

    /**
     * صف این عدد را فقط از *ویژگی* می‌خواند: پی‌لود با
     * `'timeout' => $job->timeout ?? null` ساخته می‌شود و متدی به نام timeout()
     * را هرگز صدا نمی‌زند. تا وقتی این مقدار متد بود، کارگر سقف پیش‌فرض خودش
     * (۶۰ ثانیه، چون routes/console.php به queue:work سوئیچ --timeout نمی‌دهد) را
     * اعمال می‌کرد و کار را وسط آپلود با SIGALRM می‌کشت؛ گزارش که عمداً پیش از
     * فایل فرستاده می‌شود می‌رسید و خودِ فایل هیچ‌وقت نمی‌رسید.
     */
    public int $timeout = 900;

    /**
     * @param  list<int>  $serverIds
     * @param  string  $slot  ساعت زمان‌بندی‌شده به شکل HH:MM (وقت پنل)
     */
    public function __construct(
        public array $serverIds,
        public string $slot,
    ) {
        // فرصت کافی برای SFTP چند سرور + آپلود چند ده مگابایت به تلگرام.
        $this->timeout = max(600, (int) config('shahpanel.server_backup.timeout_seconds', 300) * 3);
    }

    public function handle(
        ServerBackupService $backupService,
        ServerBackupArchiver $archiver,
        ServerBackupTelegramNotifier $notifier,
    ): void {
        $servers = Server::query()
            ->whereIn('id', $this->serverIds)
            ->orderBy('name')
            ->get();

        if ($servers->isEmpty()) {
            return;
        }

        $results = [];
        $temporaryZips = [];

        foreach ($servers as $server) {
            $result = $this->backupOneServer($server, $backupService, $archiver, $notifier);
            $results[] = $result;

            // زیپ فقط بستهٔ حمل است؛ اگر فرستاده شد حذفش می‌کنیم، ولی اگر
            // بزرگ‌تر از سقف تلگرام بود باید بماند چون مسیرش در پیام آمده.
            if ($result['ok'] && ! $result['too_large'] && $result['zip_path'] !== null) {
                $temporaryZips[] = $result['zip_path'];
            }
        }

        try {
            $notifier->sendBatch($this->slot, $results);
        } catch (Throwable $exception) {
            // ارسال ناموفق نباید بک‌آپِ گرفته‌شده را دور بریزد یا کارگر صف را بکشد.
            Log::warning('server_backup.telegram_batch_failed', [
                'slot' => $this->slot,
                'servers' => count($results),
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        foreach ($temporaryZips as $path) {
            $archiver->discard($path);
        }
    }

    /**
     * @return array{
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
     * }
     */
    protected function backupOneServer(
        Server $server,
        ServerBackupService $backupService,
        ServerBackupArchiver $archiver,
        ServerBackupTelegramNotifier $notifier,
    ): array {
        $row = [
            'server_id' => (int) $server->id,
            'server_name' => (string) $server->name,
            'server_type' => (string) $server->type->value,
            'ok' => false,
            'error' => null,
            'contents' => [],
            'bytes' => 0,
            'zip_path' => null,
            'zip_filename' => null,
            'too_large' => false,
        ];

        try {
            $backup = $backupService->run($server);
            $backup->setRelation('server', $server);

            $archive = $archiver->archive($backup);

            $row['ok'] = true;
            $row['contents'] = $this->contentsOf($backup);
            $row['bytes'] = (int) $archive['bytes'];
            $row['zip_path'] = (string) $archive['path'];
            $row['zip_filename'] = (string) $archive['filename'];
            $row['too_large'] = $row['bytes'] > $notifier->maxDocumentBytes();
        } catch (Throwable $exception) {
            // سکوت در خرابی بدترین حالت است: ادمین فرض می‌کند بک‌آپ گرفته شده.
            $row['error'] = $exception->getMessage();

            Log::warning('server_backup.scheduled_failed', [
                'server_id' => $server->id,
                'slot' => $this->slot,
                'error' => $exception->getMessage(),
            ]);
        }

        return $row;
    }

    /**
     * What was actually captured — section names for the JSON panels, file
     * names for the file-based providers (MikroTik's native backup, the Sanaei
     * panel database).
     *
     * @return list<string>
     */
    protected function contentsOf(ServerBackup $backup): array
    {
        $manifest = is_array($backup->manifest) ? $backup->manifest : [];

        if (in_array($manifest['format'] ?? '', ServerBackupService::FILE_FORMATS, true)) {
            return $backup->backupFileNames();
        }

        return array_values(array_filter(
            $backup->sectionNames(),
            static fn (string $section): bool => $section !== '_errors',
        ));
    }
}
