<?php

namespace Modules\ShahBot\Console;

use Illuminate\Console\Command;
use Modules\ShahBot\Services\OnlinePaymentService;

class GatewaySyncCommand extends Command
{
    protected $signature = 'shahbot:gateway-sync';

    protected $description = 'Tell bot users about online payments that finished';

    public function handle(OnlinePaymentService $online): int
    {
        $this->info('Notified: '.$online->notifyFinished());

        return self::SUCCESS;
    }
}
