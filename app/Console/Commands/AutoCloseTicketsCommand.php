<?php

namespace App\Console\Commands;

use App\Services\SupportTicketService;
use Illuminate\Console\Command;

class AutoCloseTicketsCommand extends Command
{
    protected $signature = 'tickets:auto-close';

    protected $description = 'Close stale support tickets based on admin settings';

    public function handle(SupportTicketService $ticketService): int
    {
        $count = $ticketService->autoCloseStaleTickets();

        $this->info("Closed {$count} ticket(s).");

        return self::SUCCESS;
    }
}
