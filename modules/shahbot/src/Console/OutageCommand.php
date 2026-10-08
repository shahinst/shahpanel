<?php

namespace Modules\ShahBot\Console;

use Illuminate\Console\Command;
use Modules\ShahBot\Services\OutageService;

class OutageCommand extends Command
{
    protected $signature = 'shahbot:outages';

    protected $description = 'Track server outages and add the lost time to the accounts on them';

    public function handle(OutageService $outages): int
    {
        $this->info('Accounts compensated: '.$outages->run());

        return self::SUCCESS;
    }
}
