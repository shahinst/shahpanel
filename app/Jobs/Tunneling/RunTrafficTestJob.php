<?php

namespace App\Jobs\Tunneling;

use App\Models\TunnelGroup;
use App\Services\Tunneling\TrafficTestService;

/**
 * Final step of the «config & test» chain: per-agent transport ping report
 * stored on the group meta for the UI.
 */
class RunTrafficTestJob extends BaseTunnelingJob
{
    public function __construct(public int $groupId)
    {
        parent::__construct();
    }

    public function handle(TrafficTestService $tester): void
    {
        $group = TunnelGroup::query()->with('iranServer', 'exits.server', 'exits.agents')->find($this->groupId);

        if ($group === null || $group->iranServer === null) {
            return;
        }

        $this->withServerLock($group->iranServer->id, function () use ($tester, $group): void {
            $tester->run($group);
        });
    }
}
