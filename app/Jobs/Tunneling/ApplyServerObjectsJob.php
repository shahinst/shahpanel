<?php

namespace App\Jobs\Tunneling;

use App\Models\Server;
use App\Services\RouterOs\DesiredStateApplier;
use App\Services\Tunneling\ManagedInterfaceService;

/**
 * Converges ALL desired objects of one router (managed interfaces + every
 * group) — used by interface creation and manual «reconcile now».
 */
class ApplyServerObjectsJob extends BaseTunnelingJob
{
    public function __construct(public int $serverId)
    {
        parent::__construct();
    }

    public function handle(DesiredStateApplier $applier, ManagedInterfaceService $interfaces): void
    {
        $server = Server::query()->find($this->serverId);

        if ($server === null || ! $server->isMikrotik()) {
            return;
        }

        $this->withServerLock($server->id, function () use ($applier, $interfaces, $server): void {
            $applier->applyForServer($server);
            $interfaces->finalizeForServer($server->id);
        });
    }
}
