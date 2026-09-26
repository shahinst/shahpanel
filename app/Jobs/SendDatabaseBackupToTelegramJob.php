<?php

namespace App\Jobs;

use App\Services\DatabaseBackupService;
use App\Services\ServerBackup\ServerBackupArchiver;
use App\Services\ServerBackup\ServerBackupTelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dumps the panel's own database at one scheduled time and delivers it to the
 * admin's Telegram chat. It reuses the existing pieces end to end: the dump
 * comes from DatabaseBackupService (the same one `backup:database` uses, so the
 * file lands in storage/app/backups/database and obeys the same retention), the
 * zip from ServerBackupArchiver and the delivery from
 * ServerBackupTelegramNotifier.
 *
 * Separate from SendServerBackupToTelegramJob on purpose: the admin turns this
 * schedule on and picks its times independently of any server.
 */
class SendDatabaseBackupToTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * تلاش مجدد یعنی یک دامپ دیگر از کل دیتابیس و پیام تکراری برای ادمین؛
     * خطاها به‌جای retry داخل همین اجرا گزارش می‌شوند.
     */
    public int $tries = 1;

    /**
     * صف این عدد را فقط از *ویژگی* می‌خواند (`'timeout' => $job->timeout ?? null`)
     * و متدی به نام timeout() را صدا نمی‌زند؛ اگر این‌جا هم متد بود، کارگر سقف
     * پیش‌فرض ۶۰ ثانیه‌اش را اعمال می‌کرد و دامپِ نیم‌گیگابایتی وسط کار کشته
     * می‌شد. نیم ساعت برای دامپ + gzip + آپلود چند ده مگابایت است.
     */
    public int $timeout = 1800;

    /**
     * @param  string  $slot  ساعت زمان‌بندی‌شده به شکل HH:MM (وقت پنل)
     */
    public function __construct(
        public string $slot,
    ) {}

    public function handle(
        DatabaseBackupService $backupService,
        ServerBackupArchiver $archiver,
        ServerBackupTelegramNotifier $notifier,
    ): void {
        $row = [
            'ok' => false,
            'error' => null,
            'database' => $this->databaseName(),
            'driver' => (string) config('database.default'),
            'bytes' => 0,
            'zip_path' => null,
            'zip_filename' => null,
            'too_large' => false,
        ];

        try {
            $backup = $backupService->createBackup();
            $row['driver'] = (string) $backup['driver'];

            $archive = $archiver->archiveFile(
                (string) $backup['path'],
                'panel-database-'.now()->format('Y-m-d_His').'.zip',
            );

            $row['ok'] = true;
            $row['bytes'] = (int) $archive['bytes'];
            $row['zip_path'] = (string) $archive['path'];
            $row['zip_filename'] = (string) $archive['filename'];
            $row['too_large'] = $row['bytes'] > $notifier->maxDocumentBytes();
        } catch (Throwable $exception) {
            // سکوت در خرابی بدترین حالت است: ادمین فرض می‌کند بک‌آپ گرفته شده.
            $row['error'] = $exception->getMessage();

            Log::warning('database_backup.telegram_failed', [
                'slot' => $this->slot,
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $notifier->sendDatabaseBackup($this->slot, $row);
        } catch (Throwable $exception) {
            // ارسال ناموفق نباید دامپِ گرفته‌شده را دور بریزد یا کارگر صف را بکشد.
            Log::warning('database_backup.telegram_batch_failed', [
                'slot' => $this->slot,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        // زیپ فقط بستهٔ حمل است و خودِ دامپ سر جایش می‌ماند؛ ولی اگر از سقف
        // تلگرام بزرگ‌تر بود باید بماند، چون مسیرش در پیام به ادمین رفته است.
        if ($row['ok'] && ! $row['too_large'] && $row['zip_path'] !== null) {
            $archiver->discard($row['zip_path']);
        }
    }

    /** What the message names as "what was backed up". */
    protected function databaseName(): string
    {
        $connection = (string) config('database.default');

        return (string) config('database.connections.'.$connection.'.database', $connection);
    }
}
