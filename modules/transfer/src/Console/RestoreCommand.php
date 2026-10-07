<?php

namespace Modules\Transfer\Console;

use Illuminate\Console\Command;
use Modules\Transfer\Services\TransferService;

class RestoreCommand extends Command
{
    protected $signature = 'transfer:restore {job : The upload id started from the transfer page}';

    protected $description = 'Replace this panel with an uploaded panel backup (started from the transfer page)';

    public function handle(TransferService $transfer): int
    {
        $transfer->run((string) $this->argument('job'));

        return ($transfer->status((string) $this->argument('job'))['state'] ?? null) === 'done'
            ? self::SUCCESS
            : self::FAILURE;
    }
}
