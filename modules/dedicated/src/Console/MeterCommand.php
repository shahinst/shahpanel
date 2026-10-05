<?php

namespace Modules\Dedicated\Console;

use Illuminate\Console\Command;
use Modules\Dedicated\Models\DedicatedServer;
use Modules\Dedicated\Services\UsageMeter;

class MeterCommand extends Command
{
    protected $signature = 'dedicated:meter';

    protected $description = 'خواندن مصرف دانلود سرورهای نمایندگان اختصاصی از اینترفیس رو به اینترنت';

    public function handle(UsageMeter $meter): int
    {
        $read = 0;

        DedicatedServer::query()->with('server')->whereNotNull('meter_interface')->each(function (DedicatedServer $row) use ($meter, &$read): void {
            $read += $meter->read($row) ? 1 : 0;
        });

        $this->info("read: {$read}");

        return self::SUCCESS;
    }
}
