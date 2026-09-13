<?php

namespace Modules\Tunneling\Console;

use App\Models\TunnelGroup;
use App\Services\Tunneling\TunnelTeardownService;
use App\Support\TunnelTeardownBackground;
use Illuminate\Console\Command;
use Throwable;

class TunnelingTeardownGroupCommand extends Command
{
    protected $signature = 'tunneling:teardown-group {group : Tunnel group ID}';

    protected $description = 'Teardown one tunnel group (CLI / background — no queue worker needed)';

    public function handle(TunnelTeardownService $teardown): int
    {
        $groupId = (int) $this->argument('group');

        @set_time_limit(0);

        $group = TunnelGroup::query()->find($groupId);

        if ($group === null) {
            $this->warn("Tunnel group {$groupId} already removed.");
            TunnelTeardownBackground::markDone($groupId, ['router_errors' => 0]);

            return self::SUCCESS;
        }

        $this->info(sprintf('Teardown group #%d (%s)', $group->id, $group->name));

        try {
            $result = $teardown->run($groupId);

            TunnelTeardownBackground::markDone($groupId, $result);

            $this->newLine();
            $this->info(sprintf(
                'Done. deleted=%s router_errors=%d',
                $result['deleted'] ? 'yes' : 'no',
                $result['router_errors'],
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            TunnelTeardownBackground::markFailed($groupId, $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
