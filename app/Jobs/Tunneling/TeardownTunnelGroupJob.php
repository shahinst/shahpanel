<?php

namespace App\Jobs\Tunneling;

use App\Services\Tunneling\TunnelTeardownService;

/**
 * Queue fallback for teardown — HTTP uses tunneling:teardown-group in background.
 */
class TeardownTunnelGroupJob extends BaseTunnelingJob
{
    public function __construct(public int $groupId)
    {
        parent::__construct();
    }

    public function handle(TunnelTeardownService $teardown): void
    {
        $teardown->run($this->groupId);
    }
}
