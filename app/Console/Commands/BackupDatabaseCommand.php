<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use RuntimeException;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Create a daily database backup';

    public function handle(DatabaseBackupService $backupService): int
    {
        try {
            $result = $backupService->createBackup();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup stored at '.$result['path']);

        return self::SUCCESS;
    }
}

