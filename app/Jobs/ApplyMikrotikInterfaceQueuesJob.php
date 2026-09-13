<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\ServerInterface;
use App\Services\MikrotikQueueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApplyMikrotikInterfaceQueuesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public int $serverInterfaceId) {}

    public function handle(MikrotikQueueService $queues): void
    {
        $iface = ServerInterface::query()->find($this->serverInterfaceId);
        $server = $iface ? Server::query()->find($iface->server_id) : null;

        if ($iface === null || $server === null || ! $server->isMikrotik()) {
            return;
        }

        try {
            $count = $queues->applyInterfaceSpeedQueues($server, $iface);
            Log::info('MikroTik interface queues applied', [
                'server_id' => $server->id,
                'interface' => $iface->name,
                'queues' => $count,
            ]);
        } catch (Throwable $e) {
            Log::error('MikroTik interface queues failed', [
                'server_id' => $server->id,
                'interface' => $iface->name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
