<?php

namespace Modules\Watchdog\Console;

use Illuminate\Console\Command;
use Modules\Watchdog\Services\WatchdogService;

class CheckCommand extends Command
{
    protected $signature = 'watchdog:check';

    protected $description = 'Check servers, disk space, SSL certificates and AnyConnect IP pools, and alert the admin on Telegram';

    public function handle(WatchdogService $watchdog): int
    {
        $problems = array_filter($watchdog->run(), fn (array $check): bool => ! $check['ok']);
        $this->info('Problems: '.count($problems));

        return self::SUCCESS;
    }
}
