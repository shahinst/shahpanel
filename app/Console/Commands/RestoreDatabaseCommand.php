<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use RuntimeException;

class RestoreDatabaseCommand extends Command
{
    protected $signature = 'backup:restore
                            {path : مسیر فایل بک‌آپ (.sql / .sqlite) یا نام فایل داخل storage/app/backups/database}
                            {--force : بدون تأیید}';

    protected $description = 'Restore database from a backup file on the server (for large files via SSH/SCP)';

    public function handle(DatabaseBackupService $backupService): int
    {
        $path = (string) $this->argument('path');

        if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            $path = storage_path('app/backups/database/'.basename($path));
        }

        if (! is_file($path)) {
            $this->error('فایل یافت نشد: '.$path);

            return self::FAILURE;
        }

        $sizeMb = round(filesize($path) / 1024 / 1024, 2);
        $this->line('فایل: '.$path);
        $this->line('حجم: '.$sizeMb.' MB');

        if (! $this->option('force') && ! $this->confirm('بازیابی تمام داده‌های فعلی دیتابیس را جایگزین می‌کند. ادامه می‌دهید؟', false)) {
            $this->warn('لغو شد.');

            return self::SUCCESS;
        }

        try {
            $backupService->restoreFromPath($path);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('بازیابی ناموفق بود: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('بازیابی دیتابیس با موفقیت انجام شد.');

        return self::SUCCESS;
    }
}
