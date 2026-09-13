<?php

namespace Modules\Tunneling\Console;

use App\Enums\TunnelGroupStatus;
use App\Models\TunnelGroup;
use App\Services\Tunneling\TunnelGroupOrchestrator;
use App\Support\TunnelConfigureBackground;
use Illuminate\Console\Command;
use Throwable;

class TunnelingConfigureGroupCommand extends Command
{
    protected $signature = 'tunneling:configure-group {group : Tunnel group ID}';

    protected $description = 'Full configure-and-test pass for one tunnel group (CLI / background)';

    public function handle(TunnelGroupOrchestrator $orchestrator): int
    {
        $groupId = (int) $this->argument('group');

        $group = TunnelGroup::query()->find($groupId);

        if ($group === null) {
            $this->error("Tunnel group {$groupId} not found.");
            TunnelConfigureBackground::markFailed($groupId, 'group not found');

            return self::FAILURE;
        }

        @set_time_limit(0);

        $this->info(sprintf('Configure & test group #%d (%s)', $group->id, $group->name));

        try {
            $orchestrator->configureAndTest($group, sync: true, progressGroupId: $groupId);

            $group->refresh();

            TunnelConfigureBackground::markDone($groupId, [
                'group_status' => $group->status->value,
                'failed' => $group->status === TunnelGroupStatus::Error ? 1 : 0,
                'configure_result' => $group->meta['configure_result'] ?? null,
            ]);

            $this->newLine();
            $this->info('Done. status='.$group->status->value.($group->status_message ? ' — '.$group->status_message : ''));

            return $group->status === TunnelGroupStatus::Error ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            TunnelConfigureBackground::markFailed($groupId, $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
