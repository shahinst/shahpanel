<?php

namespace App\Services\Tunneling;

use App\Enums\DesiredObjectStatus;
use App\Enums\TunnelGroupStatus;
use App\Enums\TunnelKind;
use App\Jobs\Tunneling\ApplyTunnelGroupJob;
use App\Jobs\Tunneling\ProbeMtuJob;
use App\Jobs\Tunneling\RunTrafficTestJob;
use App\Models\ConfigVersion;
use App\Models\DesiredNetworkObject;
use App\Models\ManagedInterface;
use App\Models\Server;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;
use App\Models\TunnelGroupEvent;
use App\Models\TunnelGroupExit;
use App\Services\RouterOs\RouterManagedWipeService;
use App\Support\TunnelJobDispatcher;
use App\Support\TunnelTeardownBackground;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Translates a tunnel group definition into desired network objects for every
 * involved router and queues the apply. Adding an exit and re-provisioning
 * wires new agents automatically; nothing is written to routers here — only
 * desired state + queued jobs.
 */
class TunnelGroupOrchestrator
{
    public function __construct(
        protected Builders\BuilderRegistry $builders,
        protected IpPoolService $ipPools,
        protected MtuCalculator $mtuCalculator,
        protected LoadBalancerService $loadBalancer,
        protected RouterManagedWipeService $routerWipe,
        protected ManagedInterfaceService $managedInterfaces,
    ) {
    }

    /**
     * Synchronous: remove all panel markers (vpnl:) from involved routers, then
     * rebuild desired state and apply immediately (no queue).
     *
     * @return array{removed: int, errors: int, apply: array<string, int>|null}
     */
    public function wipeAndApplySync(TunnelGroup $group, ?callable $progress = null): array
    {
        @set_time_limit(max(600, (int) config('tunneling.queue.job_timeout', 180) * 3));

        $wipe = $this->routerWipe->wipeGroupRouters($group, entireServer: true, progress: $progress);

        DesiredNetworkObject::query()
            ->where('tunnel_group_id', $group->id)
            ->update([
                'status' => DesiredObjectStatus::Pending->value,
                'last_error' => null,
            ]);

        if ($progress !== null) {
            $progress('rebuilding desired state…');
        }

        $this->prepare($group, 'wipe-reapply');

        if ($progress !== null) {
            $progress('applying to routers…');
        }

        app(Dispatcher::class)->dispatchSync(new ApplyTunnelGroupJob($group->id));

        $group->refresh();

        if ($progress !== null) {
            $progress('apply finished.');
        }

        return [
            'removed' => $wipe['removed'],
            'errors' => $wipe['errors'],
            'servers' => $wipe['servers'],
            'apply' => null,
        ];
    }

    /**
     * Synchronous router wipe only (no re-apply).
     *
     * @param  callable(string): void|null  $progress
     * @return array{removed: int, errors: int, servers: list<string>}
     */
    public function wipeRoutersSync(TunnelGroup $group, ?callable $progress = null): array
    {
        @set_time_limit(300);

        $wipe = $this->routerWipe->wipeGroupRouters($group, entireServer: true, progress: $progress);

        DesiredNetworkObject::query()
            ->where('tunnel_group_id', $group->id)
            ->update([
                'status' => DesiredObjectStatus::Pending->value,
                'last_error' => null,
            ]);

        $group->forceFill([
            'status' => TunnelGroupStatus::Draft,
            'status_message' => null,
        ])->save();

        return [
            'removed' => $wipe['removed'],
            'errors' => $wipe['errors'],
            'servers' => $wipe['servers'],
        ];
    }

    /**
     * Full provisioning pass: sync agents, regenerate desired objects,
     * snapshot a config version and queue the apply.
     */
    public function provision(TunnelGroup $group, string $reason = 'provision'): void
    {
        $this->prepare($group, $reason);

        TunnelJobDispatcher::dispatch(new ApplyTunnelGroupJob($group->id));
    }

    /**
     * «Config & test»: build desired state then apply + MTU + traffic test.
     * Never run with sync=true from HTTP — use TunnelConfigureBackground instead.
     */
    public function configureAndTest(TunnelGroup $group, bool $sync = false, ?int $progressGroupId = null): void
    {
        $group->loadMissing('iranServer', 'location', 'exits.server', 'exits.agents');
        $this->markConfigureStep($progressGroupId, 'prepare');
        $this->ensureClientInfrastructure($group);

        if ($group->mtu_calculated === null) {
            $group->mtu_calculated = $this->mtuCalculator->calculate($group->kind, $group->ipsec_enabled);
        }

        $this->syncAgents($group);

        if ($sync) {
            $this->markConfigureStep($progressGroupId, 'mtu');
            $this->probeMtuInline($group);
        }

        $this->buildDesiredObjects($group);
        $this->snapshot($group, 'configure-and-test');

        $group->forceFill([
            'status' => TunnelGroupStatus::Applying,
            'status_message' => null,
        ])->save();

        TunnelGroupEvent::record('provision', "صف اعمال گروه «{$group->name}» (configure-and-test).", [
            'tunnel_group_id' => $group->id,
        ]);

        $jobs = [
            'apply' => new ApplyTunnelGroupJob($group->id),
            'traffic' => new RunTrafficTestJob($group->id),
        ];

        if (! $sync) {
            TunnelJobDispatcher::dispatchChain([
                $jobs['apply'],
                new ProbeMtuJob($group->id),
                $jobs['traffic'],
            ]);

            return;
        }

        @set_time_limit(max(900, 3 * ((int) config('tunneling.queue.job_timeout', 180) + 60)));

        $dispatcher = app(Dispatcher::class);

        foreach ($jobs as $step => $job) {
            $this->markConfigureStep($progressGroupId, $step);
            $dispatcher->dispatchSync($job);
        }

        $this->markConfigureStep($progressGroupId, 'report');
        app(ConfigureResultService::class)->build($group->fresh());

        $this->markConfigureStep($progressGroupId, 'finished');
    }

    protected function probeMtuInline(TunnelGroup $group): void
    {
        if ($group->iranServer === null) {
            return;
        }

        $iran = $group->iranServer;
        $results = [];
        $minUnderlay = null;

        foreach ($group->exits as $exit) {
            $target = $exit->server?->apiConnectionHost();

            if ($target === null || $target === '') {
                continue;
            }

            $underlay = $this->mtuCalculator->probeUnderlayMtu($iran, $target);
            $results[] = [
                'exit' => $exit->server->name,
                'target' => $target,
                'underlay_mtu' => $underlay,
            ];

            if ($underlay !== null) {
                $minUnderlay = $minUnderlay === null ? $underlay : min($minUnderlay, $underlay);
            }
        }

        $meta = array_merge($group->meta ?? [], [
            'mtu_probe' => ['probed_at' => now()->toIso8601String(), 'results' => $results],
        ]);

        if ($minUnderlay !== null) {
            $overhead = $group->kind->overheadBytes()
                + ($group->ipsec_enabled ? TunnelKind::ipsecOverheadBytes() : 0);
            $group->mtu_probed = max(576, $minUnderlay - $overhead);
        }

        $group->forceFill(['meta' => $meta])->save();
    }

    protected function markConfigureStep(?int $groupId, string $step): void
    {
        if ($groupId === null) {
            return;
        }

        \App\Support\TunnelConfigureBackground::setStep($groupId, $step);
    }

    protected function prepare(TunnelGroup $group, string $reason): void
    {
        $group->loadMissing('iranServer', 'exits.server', 'exits.agents');

        if ($group->exits->isEmpty()) {
            throw new RuntimeException(__('services.tunnel_group_needs_egress'));
        }

        if ($group->mtu_calculated === null) {
            $group->mtu_calculated = $this->mtuCalculator->calculate($group->kind, $group->ipsec_enabled);
        }

        $this->syncAgents($group);
        $this->buildDesiredObjects($group);
        $this->snapshot($group, $reason);

        $group->forceFill([
            'status' => TunnelGroupStatus::Applying,
            'status_message' => null,
        ])->save();

        TunnelGroupEvent::record('provision', "صف اعمال گروه «{$group->name}» ({$reason}).", [
            'tunnel_group_id' => $group->id,
        ]);
    }

    /**
     * Light re-apply: regenerate desired objects (e.g. after reweigh or agent
     * health change) and queue the apply — no agent sync, no new snapshot.
     */
    public function reapply(TunnelGroup $group, string $reason = 'reapply'): void
    {
        $group->loadMissing('iranServer', 'exits.server', 'exits.agents');
        $this->buildDesiredObjects($group);

        TunnelGroupEvent::record('reapply', "بازاعمال گروه «{$group->name}» ({$reason}).", [
            'tunnel_group_id' => $group->id,
        ]);

        TunnelJobDispatcher::dispatch(new ApplyTunnelGroupJob($group->id));
    }

    /**
     * Graceful teardown: every desired object flips to removing, the teardown
     * job converges the routers and then deletes agents + IP allocations.
     */
    public function teardown(TunnelGroup $group): bool
    {
        if ($group->status !== TunnelGroupStatus::Removing) {
            DesiredNetworkObject::query()
                ->where('tunnel_group_id', $group->id)
                ->update(['status' => DesiredObjectStatus::Removing->value]);

            $group->forceFill(['status' => TunnelGroupStatus::Removing])->save();

            TunnelGroupEvent::record('teardown', "شروع حذف گروه «{$group->name}».", [
                'tunnel_group_id' => $group->id,
            ], 'warning');
        }

        if (TunnelTeardownBackground::isRunning($group->id)) {
            return false;
        }

        return TunnelTeardownBackground::dispatch($group->id);
    }

    /**
     * Reverse the dial direction. Desired objects (clients, routes, NAT) are
     * regenerated from the same definition, so nothing is lost — apply
     * converges each router to the new layout and prunes the old side.
     */
    public function reverse(TunnelGroup $group): void
    {
        $group->direction = $group->direction->opposite();
        $group->save();

        $this->provision($group, 'reverse');
    }

    /**
     * Rollback to a stored config version: desired objects are replaced by the
     * snapshot and re-applied; orphaned markers get pruned by the same pass.
     */
    public function rollback(TunnelGroup $group, ConfigVersion $version): void
    {
        if ($version->tunnel_group_id !== $group->id) {
            throw new RuntimeException(__('services.tunnel_revision_mismatch'));
        }

        $snapshot = $version->snapshot;

        $group->forceFill(array_intersect_key($snapshot['group'] ?? [], array_flip([
            'kind', 'direction', 'balancing_mode', 'agents_per_exit', 'circuit_id',
            'mtu_calculated', 'mtu_probed', 'mss_clamp', 'ipsec_enabled',
        ])))->save();

        DesiredNetworkObject::query()->where('tunnel_group_id', $group->id)->delete();

        foreach ($snapshot['objects'] ?? [] as $object) {
            DesiredNetworkObject::create([
                'server_id' => $object['server_id'],
                'tunnel_group_id' => $group->id,
                'object_type' => $object['object_type'],
                'menu' => $object['menu'],
                'marker' => $object['marker'],
                'payload' => $object['payload'],
                'status' => DesiredObjectStatus::Pending,
            ]);
        }

        $group->forceFill(['status' => TunnelGroupStatus::Applying])->save();

        TunnelGroupEvent::record('rollback', "بازگشت گروه «{$group->name}» به نسخه {$version->version}.", [
            'tunnel_group_id' => $group->id,
        ], 'warning');

        TunnelJobDispatcher::dispatch(new ApplyTunnelGroupJob($group->id));
    }

    /**
     * Create/remove exit rows for the given server id list; removed exits
     * drop their agents (and transport allocations) so the next provision
     * marks their router objects stale. Shared by the advanced form
     * (TunnelGroupController) and the tunneling wizard (TunnelWizardService).
     *
     * @param  list<int>  $exitIds
     */
    public function syncExits(TunnelGroup $group, array $exitIds): void
    {
        $group->load('exits.agents');

        foreach ($group->exits as $exit) {
            if (! in_array($exit->server_id, $exitIds, true)) {
                foreach ($exit->agents as $agent) {
                    $this->ipPools->releaseFor($agent);
                    $agent->delete();
                }

                $exit->delete();
            }
        }

        $existing = $group->exits()->pluck('server_id')->all();
        $position = (int) $group->exits()->max('position');

        foreach ($exitIds as $serverId) {
            if (! in_array($serverId, $existing, true)) {
                TunnelGroupExit::create([
                    'tunnel_group_id' => $group->id,
                    'server_id' => $serverId,
                    'position' => ++$position,
                    'status' => 'pending',
                ]);
            }
        }

        $group->load('exits.server', 'exits.agents');
    }

    /**
     * Ensure each exit owns the right set of agents with allocated transport
     * networks, IDs, ports and deterministic interface names.
     *
     * In `single`/`balanced` kindSelectionMode this is exactly
     * agents_per_exit agents (unchanged legacy behavior — round-robin kind
     * per seq). In `priority` mode (active/passive failover between several
     * tunnel kinds) each exit gets a FULL set of agents_per_exit agents for
     * EVERY kind in priorityKindOrder(); only the currently active kind's
     * agents are enabled, the rest are provisioned but disabled standbys
     * that TunnelKindFailoverService can promote later.
     */
    public function syncAgents(TunnelGroup $group): void
    {
        $priorityKinds = $group->kindSelectionMode() === 'priority' ? $group->priorityKindOrder() : [];
        $priorityMode = count($priorityKinds) > 1;
        $activeKind = $priorityMode ? $group->activeKind() : null;
        $perExit = $group->agents_per_exit;
        $maxSeq = $priorityMode ? $perExit * count($priorityKinds) : $perExit;

        foreach ($group->exits as $exit) {
            $existing = $exit->agents->keyBy('seq');

            for ($seq = 1; $seq <= $maxSeq; $seq++) {
                if ($priorityMode) {
                    $kindIndex = intdiv($seq - 1, $perExit);
                    $expectedKind = $priorityKinds[$kindIndex];
                    $expectedEnabled = $expectedKind === $activeKind;
                } else {
                    $expectedKind = $group->kindForAgentSeq($seq);
                    $expectedEnabled = true;
                }

                if ($existing->has($seq)) {
                    $agent = $existing->get($seq);

                    // Group kind changes propagate unless the agent was switched
                    // away on purpose (DPI evasion) — that choice is sticky.
                    $switched = (bool) ($agent->meta['kind_switched'] ?? false);

                    if (! $switched && $agent->kind !== $expectedKind) {
                        $agent->update(['kind' => $expectedKind]);
                    }

                    if ($agent->is_enabled !== $expectedEnabled) {
                        $agent->update(['is_enabled' => $expectedEnabled]);
                    }

                    continue;
                }

                $this->createAgent($group, $exit, $seq, $expectedKind, $expectedEnabled);
            }

            // Drop surplus agents (agents_per_exit reduced, or kind removed from priority list).
            foreach ($exit->agents as $agent) {
                if ($agent->seq > $maxSeq) {
                    $this->ipPools->releaseFor($agent);
                    $agent->delete();
                }
            }
        }

        $group->load('exits.agents');
    }

    protected function createAgent(TunnelGroup $group, TunnelGroupExit $exit, int $seq, ?TunnelKind $kind = null, bool $enabled = true): TunnelAgent
    {
        $agent = new TunnelAgent([
            'tunnel_group_id' => $group->id,
            'tunnel_group_exit_id' => $exit->id,
            'seq' => $seq,
            'kind' => $kind ?? $group->kindForAgentSeq($seq),
            'weight' => 1,
            'is_enabled' => $enabled,
            'iran_interface' => '',
            'foreign_interface' => '',
            'transport_network' => '',
            'iran_ip' => '',
            'foreign_ip' => '',
        ]);
        $agent->save();

        $network = $this->ipPools->allocate('transport', $agent);
        $pair = $this->ipPools->transportPair($network);
        $name = "vpnl-tg{$group->id}-e{$exit->position}-a{$seq}";

        $agent->forceFill([
            'iran_interface' => $name,
            'foreign_interface' => $name,
            'transport_network' => $network,
            'iran_ip' => $pair['iran'],
            'foreign_ip' => $pair['foreign'],
            'tunnel_id_value' => 1000 + $agent->id,
            'udp_port' => $this->pickUdpPort($group, $agent),
            'meta' => array_merge($agent->meta ?? [], [
                'l2tp_password' => Str::random(24),
            ]),
        ])->save();

        return $agent;
    }

    protected function pickUdpPort(TunnelGroup $group, TunnelAgent $agent): ?int
    {
        $candidates = $group->portHopPorts();

        if ($candidates === []) {
            $candidates = array_map('intval', (array) config('tunneling.dpi.port_candidates', []));
        }

        if ($candidates === []) {
            return null;
        }

        return $candidates[$agent->id % count($candidates)];
    }

    /**
     * Regenerate the full desired-object set for the group (agents × builder +
     * MSS clamp + ICMP/PMTUD + NAT/return routes + load balancing) and mark
     * everything stale as removing.
     */
    public function buildDesiredObjects(TunnelGroup $group): void
    {
        $iran = $group->iranServer;
        $seenMarkers = [];

        foreach ($group->exits as $exit) {
            $foreign = $exit->server;

            foreach ($exit->agents as $agent) {
                // Builder follows the agent's CURRENT kind (may differ from the
                // group kind after an automatic DPI switch).
                $builder = $this->builders->for($agent->kind);

                foreach ($builder->build($group, $agent, $iran, $foreign) as $spec) {
                    $server = $spec['side'] === 'iran' ? $iran : $foreign;
                    $marker = $this->marker($group, $spec['key']);
                    $this->upsertObject($server, $group, $spec['object_type'], $spec['menu'], $marker, $spec['payload']);
                    $seenMarkers[$server->id][] = $marker;
                }

                foreach ($this->mssClampSpecs($group, $agent) as $spec) {
                    $server = $spec['side'] === 'iran' ? $iran : $foreign;
                    $marker = $this->marker($group, $spec['key']);
                    $this->upsertObject($server, $group, $spec['object_type'], $spec['menu'], $marker, $spec['payload']);
                    $seenMarkers[$server->id][] = $marker;
                }
            }

            foreach ($this->exitEgressSpecs($group, $exit) as $spec) {
                $marker = $this->marker($group, $spec['key']);
                $this->upsertObject($foreign, $group, $spec['object_type'], $spec['menu'], $marker, $spec['payload']);
                $seenMarkers[$foreign->id][] = $marker;
            }
        }

        foreach ($this->pmtudSpecs($group) as $spec) {
            $server = $spec['side'] === 'iran' ? $iran : null;

            if ($server === null) {
                continue;
            }

            $marker = $this->marker($group, $spec['key']);
            $this->upsertObject($server, $group, $spec['object_type'], $spec['menu'], $marker, $spec['payload']);
            $seenMarkers[$server->id][] = $marker;
        }

        // Load-balancing objects (PCC marks / ECMP routes / return routes).
        foreach ($this->loadBalancer->desiredObjects($group) as $spec) {
            $server = $spec['server'];
            $marker = $this->marker($group, $spec['key']);
            $this->upsertObject($server, $group, $spec['object_type'], $spec['menu'], $marker, $spec['payload']);
            $seenMarkers[$server->id][] = $marker;
        }

        // Client subnet address-lists on Iran (for firewall / policy hooks).
        foreach ($this->clientAddressListSpecs($group) as $spec) {
            $marker = $this->marker($group, $spec['key']);
            $this->upsertObject($iran, $group, $spec['object_type'], $spec['menu'], $marker, $spec['payload']);
            $seenMarkers[$iran->id][] = $marker;
        }

        // Anything previously generated for this group but absent now = stale.
        $stale = DesiredNetworkObject::query()
            ->where('tunnel_group_id', $group->id)
            ->where('status', '!=', DesiredObjectStatus::Removing->value)
            ->get()
            ->filter(function (DesiredNetworkObject $object) use ($seenMarkers): bool {
                return ! in_array($object->marker, $seenMarkers[$object->server_id] ?? [], true);
            });

        foreach ($stale as $object) {
            $object->update(['status' => DesiredObjectStatus::Removing]);
        }
    }

    /**
     * Per-agent MSS clamp on both routers (TCP SYN crossing the tunnel).
     *
     * @return list<array{side: string, object_type: string, menu: string, key: string, payload: array<string, mixed>}>
     */
    protected function mssClampSpecs(TunnelGroup $group, TunnelAgent $agent): array
    {
        if (! $group->mss_clamp) {
            return [];
        }

        $specs = [];

        foreach ([
            'iran' => $agent->iran_interface,
            'foreign' => $agent->foreign_interface,
        ] as $side => $iface) {
            $suffix = $side === 'iran' ? 'ir' : 'fr';

            $specs[] = [
                'side' => $side,
                'object_type' => 'mangle',
                'menu' => '/ip/firewall/mangle',
                'key' => "a{$agent->id}:mss-{$suffix}",
                'payload' => [
                    'chain' => 'forward',
                    'protocol' => 'tcp',
                    'tcp-flags' => 'syn',
                    'out-interface' => $iface,
                    'action' => 'change-mss',
                    'new-mss' => 'clamp-to-pmtu',
                    'passthrough' => 'yes',
                ],
            ];
        }

        return $specs;
    }

    /**
     * Client interface(s) (WireGuard and/or PPP, per clientServiceTypes()) on
     * the Iran entry router when missing. «کانفیگ و تست» must deliver a
     * testable stack without manual client interface creation.
     */
    protected function ensureClientInfrastructure(TunnelGroup $group): void
    {
        $iran = $group->iranServer;

        if ($iran === null) {
            throw new RuntimeException(__('services.tunnel_group_needs_ingress'));
        }

        $group->loadMissing('location');

        foreach ($group->clientServiceTypes() as $type) {
            $exists = ManagedInterface::query()
                ->where('server_id', $iran->id)
                ->where('type', $type)
                ->where(function ($query) use ($group): void {
                    $query->where('tunnel_group_id', $group->id);

                    if ($group->location_id !== null) {
                        $query->orWhere('location_id', $group->location_id);
                    }
                })
                ->exists();

            if ($exists) {
                continue;
            }

            $interface = $this->managedInterfaces->create(
                $iran,
                $type,
                $group->location,
                $group->id,
                queueApply: false,
            );

            $label = $type === 'wireguard' ? 'WireGuard' : 'PPP';

            TunnelGroupEvent::record(
                'auto_interface',
                "اینترفیس {$label} «{$interface->name}» برای تست خودکار ساخته شد.",
                ['tunnel_group_id' => $group->id, 'server_id' => $iran->id],
            );
        }
    }

    /**
     * @return list<array{object_type: string, menu: string, key: string, payload: array<string, mixed>}>
     */
    protected function clientAddressListSpecs(TunnelGroup $group): array
    {
        $listName = "vpnl-tg{$group->id}-clients";
        $specs = [];

        foreach ($this->clientSubnets($group) as $index => $subnet) {
            $specs[] = [
                'object_type' => 'address_list',
                'menu' => '/ip/firewall/address-list',
                'key' => "clients:{$index}",
                'payload' => [
                    'list' => $listName,
                    'address' => $subnet,
                ],
            ];
        }

        return $specs;
    }

    /**
     * PMTUD must work: accept ICMP on the Iran router input chain.
     *
     * @return list<array{side: string, object_type: string, menu: string, key: string, payload: array<string, mixed>}>
     */
    protected function pmtudSpecs(TunnelGroup $group): array
    {
        return [[
            'side' => 'iran',
            'object_type' => 'filter',
            'menu' => '/ip/firewall/filter',
            'key' => 'icmp-in',
            'payload' => [
                'chain' => 'input',
                'protocol' => 'icmp',
                'action' => 'accept',
            ],
        ]];
    }

    /**
     * Foreign-exit egress: masquerade client subnets to the internet.
     *
     * @return list<array{object_type: string, menu: string, key: string, payload: array<string, mixed>}>
     */
    protected function exitEgressSpecs(TunnelGroup $group, TunnelGroupExit $exit): array
    {
        $specs = [];

        foreach ($this->clientSubnets($group) as $index => $subnet) {
            $specs[] = [
                'object_type' => 'nat',
                'menu' => '/ip/firewall/nat',
                'key' => "e{$exit->id}:nat-{$index}",
                'payload' => [
                    'chain' => 'srcnat',
                    'src-address' => $subnet,
                    'action' => 'masquerade',
                ],
            ];
        }

        return $specs;
    }

    /**
     * Client-facing subnets carried by this group (managed interfaces wired to
     * the group itself or to its location).
     *
     * @return list<string>
     */
    public function clientSubnets(TunnelGroup $group): array
    {
        return ManagedInterface::query()
            ->where('server_id', $group->iran_server_id)
            ->where(function ($query) use ($group): void {
                $query->where('tunnel_group_id', $group->id);

                if ($group->location_id !== null) {
                    $query->orWhere('location_id', $group->location_id);
                }
            })
            ->pluck('subnet')
            ->unique()
            ->values()
            ->all();
    }

    protected function marker(TunnelGroup $group, string $key): string
    {
        $prefix = (string) config('tunneling.marker_prefix', 'vpnl');

        return "{$prefix}:tg{$group->id}:{$key}";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function upsertObject(
        Server $server,
        TunnelGroup $group,
        string $objectType,
        string $menu,
        string $marker,
        array $payload,
    ): void {
        $object = DesiredNetworkObject::query()->firstOrNew([
            'server_id' => $server->id,
            'marker' => $marker,
        ]);

        $changed = $object->payload !== $payload || $object->menu !== $menu;

        $object->fill([
            'tunnel_group_id' => $group->id,
            'object_type' => $objectType,
            'menu' => $menu,
            'payload' => $payload,
        ]);

        if (! $object->exists || $changed || $object->status === DesiredObjectStatus::Removing) {
            $object->status = DesiredObjectStatus::Pending;
        }

        $object->save();
    }

    /**
     * Versioned snapshot for rollback.
     */
    public function snapshot(TunnelGroup $group, string $reason): ConfigVersion
    {
        $objects = DesiredNetworkObject::query()
            ->where('tunnel_group_id', $group->id)
            ->where('status', '!=', DesiredObjectStatus::Removing->value)
            ->get(['server_id', 'object_type', 'menu', 'marker', 'payload'])
            ->map(fn (DesiredNetworkObject $o): array => [
                'server_id' => $o->server_id,
                'object_type' => $o->object_type,
                'menu' => $o->menu,
                'marker' => $o->marker,
                'payload' => $o->payload,
            ])
            ->all();

        $snapshot = [
            'group' => $group->only([
                'kind', 'direction', 'balancing_mode', 'agents_per_exit', 'circuit_id',
                'mtu_calculated', 'mtu_probed', 'mss_clamp', 'ipsec_enabled',
            ]),
            'objects' => $objects,
        ];

        $version = (int) ConfigVersion::query()->where('tunnel_group_id', $group->id)->max('version') + 1;

        return ConfigVersion::create([
            'tunnel_group_id' => $group->id,
            'version' => $version,
            'snapshot' => $snapshot,
            'checksum' => hash('sha256', json_encode($snapshot)),
            'reason' => $reason,
            'created_by' => auth()->id(),
        ]);
    }
}
