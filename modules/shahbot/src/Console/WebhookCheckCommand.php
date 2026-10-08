<?php

namespace Modules\ShahBot\Console;

use Illuminate\Console\Command;
use Modules\ShahBot\Services\WebhookMonitorService;

class WebhookCheckCommand extends Command
{
    protected $signature = 'shahbot:webhook-check';

    protected $description = 'Ask Telegram how every bot webhook is doing, repair wrong addresses and warn the admins';

    public function handle(WebhookMonitorService $monitor): int
    {
        $this->info('Bots with a problem: '.$monitor->run());

        return self::SUCCESS;
    }
}
