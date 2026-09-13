<?php

namespace App\Services\Tunneling;

use App\Enums\AgentHealth;
use App\Enums\BalancingMode;
use App\Models\ManagedInterface;
use App\Models\Server;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;
use App\Models\TunnelGroupExit;
use App\Services\RouterOs\RouteGatewayFormatter;

/**
 * Generates the load-balancing desired objects for a tunnel group:
 *
 *  PCC  — per-connection-classifier mangle rules spread client connections
 *         over agents proportionally to their weights (multiple remainders
 *         per heavy agent), one routing table + default route per agent.
 *  ECMP — one routing table, one default route whose gateway list repeats
 *         each agent's transport peer `weight` times (weighted multipath).
 *
 * Return-path routes on every foreign exit point client subnets back through
 * that exit's agents. All routes use check-gateway=ping so a dead transport
 * is bypassed by RouterOS itself between panel evaluations.
 */
class LoadBalancerService
{
    /** Cap PCC denominator: more buckets = more mangle rules per subnet. */
    private const MAX_PCC_BUCKETS = 10;

    private const MAX_ECMP_REPEAT = 4;

    public function __construct(private readonly IpRangePartitioner $partitioner = new IpRangePartitioner) {}

    /**
     * @return list<array{server: Server, object_type: string, menu: string, key: string, payload: array<string, mixed>}>
     */
    public function desiredObjects(TunnelGroup $group): array
    {
        $group->loadMissing('iranServer', 'exits.server', 'exits.agents');

        $agents = $this->usableAgents($group);

        if ($agents === []) {
            return [];
        }

        $subnets = $this->clientSubnets($group);

        $specs = match ($group->balancing_mode) {
            BalancingMode::Pcc => $this->pccSpecs($group, $agents, $subnets),
            BalancingMode::RangeSplit => $this->rangeSplitSpecs($group, $subnets),
            default => $this->ecmpSpecs($group, $agents, $subnets),
        };

        return array_merge($specs, $this->returnRouteSpecs($group, $subnets));
    }

    /**
     * Recompute agent weights from quality scores (10..100 → 1..4). Returns
     * true when any weight changed (caller should re-apply).
     */
    public function reweigh(TunnelGroup $group): bool
    {
        $changed = false;

        foreach ($this->usableAgents($group) as $agent) {
            $score = $agent->quality_score;
            $weight = match (true) {
                $score === null => 1,
                $score >= 80 => 4,
                $score >= 60 => 3,
                $score >= 40 => 2,
                default => 1,
            };

            if ($agent->weight !== $weight) {
                $agent->update(['weight' => $weight]);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @param  list<TunnelAgent>  $agents
     * @param  list<string>  $subnets
     * @return list<array{server: Server, object_type: string, menu: string, key: string, payload: array<string, mixed>}>
     */
    protected function pccSpecs(TunnelGroup $group, array $agents, array $subnets): array
    {
        $iran = $group->iranServer;
        $specs = [];

        // Distribute PCC remainders over agents proportionally to weight.
        $buckets = $this->bucketsForAgents($agents);
        $denominator = count($buckets);

        foreach ($agents as $agent) {
            $table = $this->agentTable($group, $agent);

            $specs[] = [
                'server' => $iran,
                'object_type' => 'routing_table',
                'menu' => '/routing/table',
                'key' => "a{$agent->id}:rt",
                'payload' => ['name' => $table, 'fib' => ''],
            ];

            $specs[] = [
                'server' => $iran,
                'object_type' => 'route',
                'menu' => '/ip/route',
                'key' => "a{$agent->id}:default",
                'payload' => [
                    'dst-address' => '0.0.0.0/0',
                    'gateway' => RouteGatewayFormatter::peerGateway($agent->foreign_ip),
                    'routing-table' => $table,
                    'check-gateway' => 'ping',
                    'distance' => '1',
                ],
            ];

            // connection-mark -> routing-mark (one rule per agent).
            $specs[] = [
                'server' => $iran,
                'object_type' => 'mangle',
                'menu' => '/ip/firewall/mangle',
                'key' => "a{$agent->id}:rm",
                'payload' => [
                    'chain' => 'prerouting',
                    'connection-mark' => $this->connMark($group, $agent),
                    'action' => 'mark-routing',
                    'new-routing-mark' => $table,
                    'passthrough' => 'no',
                ],
            ];
        }

        foreach ($subnets as $subnetIndex => $subnet) {
            foreach ($buckets as $remainder => $agent) {
                $specs[] = [
                    'server' => $iran,
                    'object_type' => 'mangle',
                    'menu' => '/ip/firewall/mangle',
                    'key' => "pcc:{$subnetIndex}:{$remainder}",
                    'payload' => [
                        'chain' => 'prerouting',
                        'src-address' => $subnet,
                        'dst-address-type' => '!local',
                        'connection-mark' => 'no-mark',
                        'per-connection-classifier' => "both-addresses-and-ports:{$denominator}/{$remainder}",
                        'action' => 'mark-connection',
                        'new-connection-mark' => $this->connMark($group, $agent),
                        'passthrough' => 'yes',
                    ],
                ];
            }
        }

        return $specs;
    }

    /**
     * @param  list<TunnelAgent>  $agents
     * @param  list<string>  $subnets
     * @return list<array{server: Server, object_type: string, menu: string, key: string, payload: array<string, mixed>}>
     */
    protected function ecmpSpecs(TunnelGroup $group, array $agents, array $subnets): array
    {
        $iran = $group->iranServer;
        $table = "vpnl-tg{$group->id}";
        $specs = [];

        $specs[] = [
            'server' => $iran,
            'object_type' => 'routing_table',
            'menu' => '/routing/table',
            'key' => 'rt',
            'payload' => ['name' => $table, 'fib' => ''],
        ];

        $specs[] = [
            'server' => $iran,
            'object_type' => 'route',
            'menu' => '/ip/route',
            'key' => 'default',
            'payload' => [
                'dst-address' => '0.0.0.0/0',
                'gateway' => RouteGatewayFormatter::weightedOnLink(
                    $agents,
                    fn (TunnelAgent $a): string => $a->foreign_ip,
                    fn (TunnelAgent $a): string => $a->iran_interface,
                ),
                'routing-table' => $table,
                'check-gateway' => 'ping',
                'distance' => '1',
            ],
        ];

        foreach ($subnets as $index => $subnet) {
            $specs[] = [
                'server' => $iran,
                'object_type' => 'routing_rule',
                'menu' => '/routing/rule',
                'key' => "rule:{$index}",
                'payload' => [
                    'src-address' => $subnet,
                    'action' => 'lookup-only-in-table',
                    'table' => $table,
                ],
            ];
        }

        return $specs;
    }

    /**
     * Static FIB-based split of each client subnet into one contiguous
     * address range per exit (no PCC hashing) — e.g. with 2 exits a /24
     * pool becomes .2-.128 → exit A and .129-.254 → exit B. Each exit gets
     * its own routing table + FIB default route; if an exit has multiple
     * usable agents (e.g. several tunnel kinds), traffic is spread across
     * them via weighted on-link ECMP within that exit's table.
     *
     * @param  list<string>  $subnets
     * @return list<array{server: Server, object_type: string, menu: string, key: string, payload: array<string, mixed>}>
     */
    protected function rangeSplitSpecs(TunnelGroup $group, array $subnets): array
    {
        $iran = $group->iranServer;
        $specs = [];

        $exits = $group->exits
            ->map(fn ($exit) => [
                'exit' => $exit,
                'agents' => $exit->agents
                    ->filter(fn (TunnelAgent $a): bool => $a->is_enabled && $a->health !== AgentHealth::Down)
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $row): bool => $row['agents'] !== [])
            ->values();

        if ($exits->isEmpty()) {
            return [];
        }

        foreach ($exits as $row) {
            $exit = $row['exit'];
            $table = $this->exitTable($group, $exit);

            $specs[] = [
                'server' => $iran,
                'object_type' => 'routing_table',
                'menu' => '/routing/table',
                'key' => "e{$exit->id}:rt",
                'payload' => ['name' => $table, 'fib' => ''],
            ];

            $specs[] = [
                'server' => $iran,
                'object_type' => 'route',
                'menu' => '/ip/route',
                'key' => "e{$exit->id}:default",
                'payload' => [
                    'dst-address' => '0.0.0.0/0',
                    'gateway' => RouteGatewayFormatter::weightedOnLink(
                        $row['agents'],
                        fn (TunnelAgent $a): string => $a->foreign_ip,
                        fn (TunnelAgent $a): string => $a->iran_interface,
                    ),
                    'routing-table' => $table,
                    'check-gateway' => 'ping',
                    'distance' => '1',
                ],
            ];
        }

        $exitCount = $exits->count();

        foreach ($subnets as $subnetIndex => $subnet) {
            try {
                $blocks = $this->partitioner->partition($subnet, $exitCount);
            } catch (\InvalidArgumentException) {
                continue;
            }

            foreach ($blocks as $blockIndex => $block) {
                $exit = $exits[$blockIndex]['exit'];

                $specs[] = [
                    'server' => $iran,
                    'object_type' => 'mangle',
                    'menu' => '/ip/firewall/mangle',
                    'key' => "range:{$subnetIndex}:{$exit->id}",
                    'payload' => [
                        'chain' => 'prerouting',
                        'src-address' => $block['range'],
                        'dst-address-type' => '!local',
                        'action' => 'mark-routing',
                        'new-routing-mark' => $this->exitTable($group, $exit),
                        'passthrough' => 'no',
                    ],
                ];
            }
        }

        return $specs;
    }

    /**
     * Return-path routes on every exit: client subnets → back through that
     * exit's agents (weighted ECMP across the exit's own agents).
     *
     * @param  list<string>  $subnets
     * @return list<array{server: Server, object_type: string, menu: string, key: string, payload: array<string, mixed>}>
     */
    protected function returnRouteSpecs(TunnelGroup $group, array $subnets): array
    {
        $specs = [];

        foreach ($group->exits as $exit) {
            $agents = $exit->agents
                ->filter(fn (TunnelAgent $a): bool => $a->is_enabled && $a->health !== AgentHealth::Down)
                ->values()
                ->all();

            if ($agents === []) {
                continue;
            }

            foreach ($subnets as $index => $subnet) {
                $specs[] = [
                    'server' => $exit->server,
                    'object_type' => 'route',
                    'menu' => '/ip/route',
                    'key' => "e{$exit->id}:return:{$index}",
                    'payload' => [
                        'dst-address' => $subnet,
                        'gateway' => RouteGatewayFormatter::weightedOnLink(
                            $agents,
                            fn (TunnelAgent $a): string => $a->iran_ip,
                            fn (TunnelAgent $a): string => $a->foreign_interface,
                        ),
                        'check-gateway' => 'ping',
                        'distance' => '1',
                    ],
                ];
            }
        }

        return $specs;
    }

    /**
     * @return list<TunnelAgent>
     */
    protected function usableAgents(TunnelGroup $group): array
    {
        return $group->exits
            ->flatMap(fn ($exit) => $exit->agents)
            ->filter(fn (TunnelAgent $a): bool => $a->is_enabled && $a->health !== AgentHealth::Down)
            ->values()
            ->all();
    }

    /**
     * Remainder → agent map proportional to weights, capped at MAX_PCC_BUCKETS.
     *
     * @param  list<TunnelAgent>  $agents
     * @return list<TunnelAgent>
     */
    protected function bucketsForAgents(array $agents): array
    {
        $totalWeight = max(1, array_sum(array_map(fn (TunnelAgent $a): int => max(1, $a->weight), $agents)));
        $scale = min(1.0, self::MAX_PCC_BUCKETS / $totalWeight);
        $buckets = [];

        foreach ($agents as $agent) {
            $count = max(1, (int) round(max(1, $agent->weight) * $scale));

            for ($i = 0; $i < $count; $i++) {
                $buckets[] = $agent;
            }
        }

        return array_slice($buckets, 0, self::MAX_PCC_BUCKETS);
    }

    protected function agentTable(TunnelGroup $group, TunnelAgent $agent): string
    {
        return "vpnl-tg{$group->id}-a{$agent->id}";
    }

    protected function exitTable(TunnelGroup $group, TunnelGroupExit $exit): string
    {
        return "vpnl-tg{$group->id}-e{$exit->id}";
    }

    protected function connMark(TunnelGroup $group, TunnelAgent $agent): string
    {
        return "vpnl-tg{$group->id}-a{$agent->id}-cm";
    }

    /**
     * @return list<string>
     */
    protected function clientSubnets(TunnelGroup $group): array
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
}
