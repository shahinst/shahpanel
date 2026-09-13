<?php

namespace App\Services\Tunneling;

use App\Models\ConfigVersion;
use App\Models\DesiredNetworkObject;
use App\Models\IpPoolAllocation;
use App\Models\ManagedInterface;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;
use App\Models\TunnelGroupEvent;
use App\Models\TunnelGroupExit;
use App\Models\TunnelMetricRollup;
use App\Models\TunnelMetricSample;
use App\Services\RouterOs\RouterManagedWipeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Panel delete is instant (no router API). Router wipe is CLI/cron only.
 */
class TunnelTeardownService
{
    public function __construct(
        protected RouterManagedWipeService $wipeService,
        protected IpPoolService $ipPools,
    ) {
    }

    /**
     * Instant — removes group from panel only (< 1s). No MikroTik calls.
     */
    public function purgePanelOnly(TunnelGroup $group): string
    {
        $group->loadMissing('exits.agents', 'agents');
        $name = $group->name;
        $groupId = $group->id;

        DB::transaction(function () use ($group, $groupId): void {
            $agentIds = TunnelAgent::query()
                ->where('tunnel_group_id', $groupId)
                ->pluck('id');

            if ($agentIds->isNotEmpty()) {
                TunnelMetricSample::query()->whereIn('tunnel_agent_id', $agentIds)->delete();
                TunnelMetricRollup::query()->whereIn('tunnel_agent_id', $agentIds)->delete();

                IpPoolAllocation::query()
                    ->where('owner_type', (new TunnelAgent)->getMorphClass())
                    ->whereIn('owner_id', $agentIds)
                    ->delete();
            }

            IpPoolAllocation::query()
                ->where('owner_type', (new ManagedInterface)->getMorphClass())
                ->whereIn(
                    'owner_id',
                    ManagedInterface::query()->where('tunnel_group_id', $groupId)->pluck('id'),
                )
                ->delete();

            $managedIds = ManagedInterface::query()->where('tunnel_group_id', $groupId)->pluck('id');

            if ($managedIds->isNotEmpty()) {
                DesiredNetworkObject::query()
                    ->whereIn('managed_interface_id', $managedIds)
                    ->delete();
            }

            DesiredNetworkObject::query()->where('tunnel_group_id', $groupId)->delete();
            ConfigVersion::query()->where('tunnel_group_id', $groupId)->delete();
            TunnelGroupEvent::query()->where('tunnel_group_id', $groupId)->delete();
            TunnelAgent::query()->where('tunnel_group_id', $groupId)->delete();
            ManagedInterface::query()->where('tunnel_group_id', $groupId)->delete();
            TunnelGroupExit::query()->where('tunnel_group_id', $groupId)->delete();

            $group->forceDelete();
        });

        Log::info('tunneling: panel purge done', ['group_id' => $groupId, 'name' => $name]);

        return $name;
    }

    /**
     * CLI / optional: wipe routers then purge panel.
     *
     * @return array{deleted: bool, router_errors: int, name: ?string, servers: list<string>}
     */
    public function run(int $groupId, bool $wipeRouters = true): array
    {
        $group = TunnelGroup::query()
            ->with('iranServer', 'exits.server', 'exits.agents', 'agents')
            ->find($groupId);

        if ($group === null) {
            return ['deleted' => true, 'router_errors' => 0, 'name' => null, 'servers' => []];
        }

        $name = $group->name;
        $serverNames = [];
        $routerErrors = 0;

        if ($wipeRouters) {
            @set_time_limit(max(600, (int) config('tunneling.teardown.max_seconds', 300)));
            $deadline = microtime(true) + (int) config('tunneling.teardown.router_budget_seconds', 120);

            try {
                $routerErrors = $this->removeFromAllRouters($group, $serverNames, $deadline);
            } catch (Throwable $e) {
                $routerErrors++;
                Log::error('tunneling: router wipe failed', ['group_id' => $groupId, 'error' => $e->getMessage()]);
            }
        }

        $this->purgePanelOnly($group);

        TunnelGroupEvent::record(
            'teardown_done',
            "گروه «{$name}» از پنل حذف شد.",
            ['tunnel_group_id' => null, 'detail' => ['former_group_id' => $groupId, 'router_errors' => $routerErrors]],
            $routerErrors > 0 ? 'warning' : 'ok',
        );

        return [
            'deleted' => true,
            'router_errors' => $routerErrors,
            'name' => $name,
            'servers' => $serverNames,
        ];
    }

    /**
     * Wipe routers for an already-deleted group (by ID + server list snapshot).
     *
     * @param  list<int>  $serverIds
     */
    public function wipeRoutersByGroupId(int $groupId, array $serverIds): int
    {
        $stub = new TunnelGroup(['name' => "deleted-{$groupId}"]);
        $stub->id = $groupId;

        $errors = 0;
        $deadline = microtime(true) + (int) config('tunneling.teardown.router_budget_seconds', 120);

        foreach (\App\Models\Server::query()->whereIn('id', $serverIds)->get() as $server) {
            if (microtime(true) > $deadline) {
                break;
            }

            try {
                $result = $this->wipeService->wipeServer($server, $stub);
                $errors += (int) ($result['errors'] ?? 0);
            } catch (Throwable $e) {
                $errors++;
                Log::error('tunneling: post-delete router wipe failed', [
                    'group_id' => $groupId,
                    'server_id' => $server->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $serverNames
     */
    protected function removeFromAllRouters(TunnelGroup $group, array &$serverNames, float $deadline): int
    {
        $servers = collect([$group->iranServer])
            ->merge($group->exits->map->server)
            ->filter()
            ->unique('id');

        $totalErrors = 0;

        foreach ($servers as $server) {
            if (microtime(true) > $deadline) {
                $totalErrors++;

                break;
            }

            $serverNames[] = $server->name;

            try {
                $result = $this->wipeService->wipeServer($server, $group);
                $totalErrors += (int) ($result['errors'] ?? 0);
            } catch (Throwable $e) {
                $totalErrors++;
                Log::error('tunneling: teardown wipe failed', [
                    'server_id' => $server->id,
                    'group_id' => $group->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $totalErrors;
    }
}
