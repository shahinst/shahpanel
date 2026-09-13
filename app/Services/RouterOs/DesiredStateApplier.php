<?php

namespace App\Services\RouterOs;

use App\Enums\DesiredObjectStatus;
use App\Models\DesiredNetworkObject;
use App\Models\Server;
use App\Services\MikrotikService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies desired network objects to routers in dependency order and detects
 * drift. The single convergence engine used by apply, reconcile and teardown
 * jobs.
 */
class DesiredStateApplier
{
    /**
     * Apply order: parents before dependents (an /ip/address needs its
     * interface; a route needs its address; mangle marks before NAT…).
     */
    private const TYPE_ORDER = [
        'routing_table',
        'ipsec',
        'interface',
        'wireguard',
        'ip_address',
        'address_list',
        'route',
        'mangle',
        'nat',
        'filter',
        'routing_rule',
        'script',
        'scheduler',
    ];

    public function __construct(
        protected RouterCommandService $commands,
        protected MikrotikService $mikrotik,
        protected DesiredStateScriptBuilder $scriptBuilder,
    ) {
    }

    /**
     * Converge every pending/applied/drift/error object of a server (optionally
     * scoped to one tunnel group). Objects in `removing` status are deleted from
     * the router and then from the DB.
     *
     * @return array{created: int, updated: int, unchanged: int, removed: int, failed: int}
     */
    public function applyForServer(Server $server, ?int $tunnelGroupId = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0];

        $objects = DesiredNetworkObject::query()
            ->where('server_id', $server->id)
            ->when($tunnelGroupId !== null, fn ($q) => $q->where('tunnel_group_id', $tunnelGroupId))
            ->get();

        // Removals first (frees names/ports the new objects may reuse).
        foreach ($objects->where('status', DesiredObjectStatus::Removing) as $object) {
            $this->removeObject($server, $object, $stats);
        }

        foreach ($this->sortForApply($objects->where('status', '!=', DesiredObjectStatus::Removing)) as $object) {
            $this->applyObject($server, $object, $stats);
        }

        return $stats;
    }

    /**
     * Same convergence as applyForServer(), but writes the whole batch as
     * ONE generated RouterOS script (see DesiredStateScriptBuilder) executed
     * in a single round-trip via MikrotikService::runEphemeralScript(),
     * instead of 2+ API round-trips per object. Verification afterwards is
     * also batched — one grouped `/print` per distinct menu instead of one
     * per object — so a 30-40 object tunnel-group apply that used to need
     * 70-100+ round-trips (and could exceed a 60s HTTP/gateway timeout) now
     * needs on the order of 10-15, comfortably finishing in a few seconds.
     *
     * Falls back to the slower per-object applyForServer() convergence if
     * the batch script itself fails to run (e.g. connection lost) — better
     * to be slow-but-correct than to leave everything unmarked.
     *
     * @return array{created: int, updated: int, unchanged: int, removed: int, failed: int}
     */
    public function applyForServerBatched(Server $server, ?int $tunnelGroupId = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0];

        $objects = DesiredNetworkObject::query()
            ->where('server_id', $server->id)
            ->when($tunnelGroupId !== null, fn ($q) => $q->where('tunnel_group_id', $tunnelGroupId))
            ->get();

        $removing = $objects->where('status', DesiredObjectStatus::Removing)->values();
        $applying = collect($this->sortForApply($objects->where('status', '!=', DesiredObjectStatus::Removing)));

        if ($removing->isEmpty() && $applying->isEmpty()) {
            return $stats;
        }

        $script = $this->scriptBuilder->build($removing, $applying);

        if (trim($script) !== '') {
            try {
                $timeout = max(120, (int) config('tunneling.queue.job_timeout', 180));
                $this->mikrotik->runEphemeralScript($server, $script, $timeout);
            } catch (Throwable $e) {
                Log::warning('tunneling: batch apply script failed, falling back to per-object apply', [
                    'server_id' => $server->id,
                    'error' => $e->getMessage(),
                ]);

                return $this->applyForServer($server, $tunnelGroupId);
            }
        }

        $this->verifyBatch($server, $removing, $applying, $stats);

        return $stats;
    }

    /**
     * Confirms the batch script's real effect on the router — grouped by
     * menu (one `/print` per distinct menu) instead of per object — and
     * updates each DesiredNetworkObject's status accordingly.
     *
     * @param  \Illuminate\Support\Collection<int, DesiredNetworkObject>  $removing
     * @param  \Illuminate\Support\Collection<int, DesiredNetworkObject>  $applying
     * @param  array{created: int, updated: int, unchanged: int, removed: int, failed: int}  $stats
     */
    protected function verifyBatch(Server $server, $removing, $applying, array &$stats): void
    {
        $byMenuApplying = $applying->groupBy(fn (DesiredNetworkObject $o) => rtrim($o->menu, '/'));
        $byMenuRemoving = $removing->groupBy(fn (DesiredNetworkObject $o) => rtrim($o->menu, '/'));
        $menus = $byMenuApplying->keys()->merge($byMenuRemoving->keys())->unique();

        foreach ($menus as $menu) {
            $applyingForMenu = $byMenuApplying->get($menu, collect());
            $removingForMenu = $byMenuRemoving->get($menu, collect());

            $firstNonSingleton = $applyingForMenu->concat($removingForMenu)
                ->first(fn (DesiredNetworkObject $o): bool => $o->object_type !== 'singleton');

            if ($firstNonSingleton === null) {
                // Only singletons on this menu — no comment marker to verify
                // against; trust the script (it always runs, never removed).
                foreach ($applyingForMenu as $object) {
                    $object->update([
                        'status' => DesiredObjectStatus::Applied,
                        'last_error' => null,
                        'last_applied_at' => now(),
                        'last_verified_at' => now(),
                    ]);
                    $stats['updated']++;
                }

                continue;
            }

            try {
                $actualRows = $this->commands->listManagedRows($server, $menu);
            } catch (Throwable $e) {
                Log::warning('tunneling: batch verify failed for menu', [
                    'server_id' => $server->id,
                    'menu' => $menu,
                    'error' => $e->getMessage(),
                ]);
                $actualRows = [];
            }

            $actualMarkers = [];

            foreach ($actualRows as $row) {
                $actualMarkers[(string) ($row['comment'] ?? '')] = true;
            }

            foreach ($applyingForMenu as $object) {
                if (isset($actualMarkers[$object->marker])) {
                    $stats[$object->last_applied_at === null ? 'created' : 'updated']++;

                    $object->update([
                        'status' => DesiredObjectStatus::Applied,
                        'last_error' => null,
                        'last_applied_at' => now(),
                        'last_verified_at' => now(),
                    ]);
                } else {
                    $stats['failed']++;

                    $object->update([
                        'status' => DesiredObjectStatus::Error,
                        'last_error' => 'اعمال دسته‌ای روی روتر تأیید نشد (مورد پیدا نشد).',
                    ]);
                }
            }

            foreach ($removingForMenu as $object) {
                if (isset($actualMarkers[$object->marker])) {
                    // Still there — removal failed; leave status=Removing so the next apply retries it.
                    $object->update(['last_error' => 'حذف دسته‌ای روی روتر تأیید نشد (هنوز موجود است).']);

                    continue;
                }

                $object->delete();
                $stats['removed']++;
            }
        }
    }

    /**
     * Mark every desired object of a group as removing, then converge.
     */
    public function teardownGroup(Server $server, int $tunnelGroupId): array
    {
        DesiredNetworkObject::query()
            ->where('server_id', $server->id)
            ->where('tunnel_group_id', $tunnelGroupId)
            ->update(['status' => DesiredObjectStatus::Removing->value]);

        return $this->applyForServer($server, $tunnelGroupId);
    }

    /**
     * Read-only drift detection: marks applied objects whose actual router row
     * is missing or different. Returns the drifted objects.
     *
     * @return list<DesiredNetworkObject>
     */
    public function detectDrift(Server $server): array
    {
        $drifted = [];

        $objects = DesiredNetworkObject::query()
            ->where('server_id', $server->id)
            ->where('status', DesiredObjectStatus::Applied->value)
            ->get();

        foreach ($objects as $object) {
            try {
                if ($this->commands->matchesDesired($server, $object)) {
                    $object->update(['last_verified_at' => now()]);

                    continue;
                }
            } catch (Throwable $e) {
                Log::warning('tunneling: drift check failed', [
                    'server_id' => $server->id,
                    'marker' => $object->marker,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $object->update(['status' => DesiredObjectStatus::Drift]);
            $drifted[] = $object;
        }

        return $drifted;
    }

    /**
     * @param  array{created: int, updated: int, unchanged: int, removed: int, failed: int}  $stats
     */
    protected function applyObject(Server $server, DesiredNetworkObject $object, array &$stats): void
    {
        try {
            $result = $this->commands->ensure($server, $object);
            $stats[$result]++;

            $object->update([
                'status' => DesiredObjectStatus::Applied,
                'last_error' => null,
                'last_applied_at' => $result === RouterCommandService::RESULT_UNCHANGED
                    ? ($object->last_applied_at ?? now())
                    : now(),
                'last_verified_at' => now(),
            ]);
        } catch (Throwable $e) {
            $stats['failed']++;
            $object->update([
                'status' => DesiredObjectStatus::Error,
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            Log::error('tunneling: apply object failed', [
                'server_id' => $server->id,
                'marker' => $object->marker,
                'menu' => $object->menu,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array{created: int, updated: int, unchanged: int, removed: int, failed: int}  $stats
     */
    protected function removeObject(Server $server, DesiredNetworkObject $object, array &$stats): void
    {
        try {
            $this->commands->ensureRemoved($server, $object->menu, $object->marker);
            $object->delete();
            $stats['removed']++;
        } catch (Throwable $e) {
            $stats['failed']++;
            $object->update(['last_error' => mb_substr($e->getMessage(), 0, 2000)]);

            Log::error('tunneling: remove object failed', [
                'server_id' => $server->id,
                'marker' => $object->marker,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  Collection<int, DesiredNetworkObject>|\Illuminate\Support\Collection  $objects
     * @return list<DesiredNetworkObject>
     */
    protected function sortForApply($objects): array
    {
        $order = array_flip(self::TYPE_ORDER);

        return $objects
            ->sortBy([
                fn (DesiredNetworkObject $a, DesiredNetworkObject $b) => ($order[$a->object_type] ?? 99) <=> ($order[$b->object_type] ?? 99),
                fn (DesiredNetworkObject $a, DesiredNetworkObject $b) => $a->id <=> $b->id,
            ])
            ->values()
            ->all();
    }
}
