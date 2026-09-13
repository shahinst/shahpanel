<?php

namespace Modules\Tunneling\Console;

use App\Models\TunnelGroup;
use App\Services\Tunneling\TunnelTeardownService;
use Illuminate\Console\Command;
use Throwable;

class TunnelingForceDeleteGroupCommand extends Command
{
    protected $signature = 'tunneling:force-delete-group
                            {group : Tunnel group ID}
                            {--routers : Also wipe MikroTik config (slow)}';

    protected $description = 'Delete tunnel group from panel instantly; optional router wipe';

    public function handle(TunnelTeardownService $teardown): int
    {
        $groupId = (int) $this->argument('group');

        $group = TunnelGroup::query()->with('iranServer', 'exits.server')->find($groupId);

        if ($group === null) {
            $this->warn("Group {$groupId} not found (already deleted).");

            return self::SUCCESS;
        }

        $this->info("Delete group #{$groupId} ({$group->name})");

        try {
            if ($this->option('routers')) {
                $result = $teardown->run($groupId, wipeRouters: true);
                $this->info(sprintf('Done. router_errors=%d', $result['router_errors']));
            } else {
                $name = $teardown->purgePanelOnly($group);
                $this->info("Panel deleted: {$name}");
                $this->line('Run with --routers to clean MikroTik config.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
