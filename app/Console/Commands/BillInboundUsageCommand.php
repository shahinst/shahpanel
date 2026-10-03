<?php

namespace App\Console\Commands;

use App\Services\InboundReseller\InboundAllocationService;
use Illuminate\Console\Command;

class BillInboundUsageCommand extends Command
{
    protected $signature = 'inbound:bill';

    protected $description = 'Meter inbound resellers\' traffic, charge it to their wallets and enforce their quotas';

    public function handle(InboundAllocationService $service): int
    {
        $result = $service->billAll();

        $this->info("Allocations: {$result['allocations']}, charged: {$result['billed']}");

        return self::SUCCESS;
    }
}
