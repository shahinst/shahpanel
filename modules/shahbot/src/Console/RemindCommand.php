<?php

namespace Modules\ShahBot\Console;

use Illuminate\Console\Command;
use Modules\ShahBot\Services\BalanceAlertService;
use Modules\ShahBot\Services\ReminderService;
use Modules\ShahBot\Services\WinbackService;

class RemindCommand extends Command
{
    protected $signature = 'shahbot:remind';

    protected $description = 'Warn bot users about services close to expiry or out of data';

    public function handle(ReminderService $reminders, WinbackService $winback, BalanceAlertService $balances): int
    {
        $this->info('Reminders sent: '.$reminders->run());
        $this->info('Win-back offers sent: '.$winback->run());
        $this->info('Low balance warnings: '.$balances->run());

        return self::SUCCESS;
    }
}
