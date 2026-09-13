<?php

namespace Modules\Tunneling\Console;

use App\Models\TunnelGroup;
use App\Services\RouterOs\RouterManagedWipeService;
use App\Services\Tunneling\TunnelGroupOrchestrator;
use App\Support\TunnelWipeBackground;
use Illuminate\Console\Command;
use Throwable;

class TunnelingWipeGroupCommand extends Command
{
    protected $signature = 'tunneling:wipe-group
                            {group : Tunnel group ID}
                            {--apply : Rebuild desired state and apply after wipe}
                            {--audit : After wipe, print remaining panel-like objects}';

    protected $description = 'Remove all panel tunnel config from group routers (full print sweep)';

    public function handle(
        TunnelGroupOrchestrator $orchestrator,
        RouterManagedWipeService $wipeService,
    ): int {
        $groupId = (int) $this->argument('group');
        $apply = (bool) $this->option('apply');

        $group = TunnelGroup::query()->find($groupId);
        if ($group === null) {
            $this->error("Tunnel group {$groupId} not found.");
            TunnelWipeBackground::markFailed($groupId, 'group not found');

            return self::FAILURE;
        }

        @set_time_limit(0);

        $this->info(sprintf(
            'Wiping routers for group #%d (%s)%s',
            $group->id,
            $group->name,
            $apply ? ' + apply' : '',
        ));

        $progress = fn (string $message): mixed => $this->line('  '.$message);

        try {
            $result = $apply
                ? $orchestrator->wipeAndApplySync($group, $progress)
                : $orchestrator->wipeRoutersSync($group, $progress);

            TunnelWipeBackground::markDone($groupId, array_merge($result, ['apply' => $apply]));

            $this->newLine();
            $this->info(sprintf(
                'Done. removed=%d errors=%d servers=%d',
                (int) ($result['removed'] ?? 0),
                (int) ($result['errors'] ?? 0),
                count($result['servers'] ?? []),
            ));

            if ($this->option('audit')) {
                $this->newLine();
                $this->warn('Audit (remaining rows with panel markers):');
                $group->loadMissing('iranServer', 'exits.server');
                $servers = collect([$group->iranServer])->merge($group->exits->map->server)->filter()->unique('id');
                foreach ($servers as $server) {
                    $audit = $wipeService->auditServer($server, $group);
                    $total = array_sum($audit);
                    $this->line("  {$server->name}: {$total} remaining");
                    foreach ($audit as $label => $count) {
                        if ($count > 0) {
                            $this->line("    {$label}: {$count}");
                        }
                    }
                }
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            TunnelWipeBackground::markFailed($groupId, $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
