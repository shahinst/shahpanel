<?php

namespace Modules\ShahBot\Console;

use Illuminate\Console\Command;
use Modules\ShahBot\Services\FunService;

class LotteryCommand extends Command
{
    protected $signature = 'shahbot:lottery';

    protected $description = 'Draw the bot lotteries whose time has come';

    public function handle(FunService $fun): int
    {
        $this->info('Drawn: '.$fun->drawDue());

        return self::SUCCESS;
    }
}
