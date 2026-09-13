<?php

namespace App\Jobs\CrmTunneling;

use App\CrmTunneling\TunnelManager;
use App\Models\CrmTunnel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProvisionTunnelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public function __construct(public int $tunnelId)
    {
        $this->onQueue((string) config('crm_tunnel.queue', 'default'));
    }

    public function handle(TunnelManager $manager): void
    {
        $tunnel = CrmTunnel::query()->find($this->tunnelId);

        if ($tunnel === null) {
            return;
        }

        $manager->deploy($tunnel);
    }
}
