<?php

namespace App\Jobs\Tunneling;

use App\Jobs\Tunneling\Concerns\LocksServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class BaseTunnelingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use LocksServer;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct()
    {
        $this->onConnection((string) config('tunneling.queue.connection', 'database'));
        $this->onQueue((string) config('tunneling.queue.name', 'tunneling'));
        $this->timeout = (int) config('tunneling.queue.job_timeout', 180);
    }

    public int $timeout = 180;
}
