<?php

namespace App\Jobs\Concerns;

/**
 * Sends a long-running job to the separate backups queue when the panel uses
 * the database queue (the default). Other drivers keep their own routing.
 */
trait RunsOnBackupQueue
{
    protected function useBackupQueue(): void
    {
        if (config('queue.default') === 'database') {
            $this->onConnection('database_long');
            $this->onQueue('backups');
        }
    }
}
