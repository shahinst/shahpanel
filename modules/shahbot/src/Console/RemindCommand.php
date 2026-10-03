<?php

namespace Modules\ShahBot\Console;

use Illuminate\Console\Command;
use Modules\ShahBot\Services\ReminderService;

class RemindCommand extends Command
{
    protected $signature = 'shahbot:remind';

    protected $description = 'Warn bot users about services close to expiry or out of data';

    public function handle(ReminderService $reminders): int
    {
        $this->info('Reminders sent: '.$reminders->run());

        return self::SUCCESS;
    }
}
