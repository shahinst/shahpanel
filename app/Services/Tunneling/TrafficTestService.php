<?php

namespace App\Services\Tunneling;

use App\Models\TunnelGroup;
use App\Models\TunnelGroupEvent;

/**
 * Per-agent connectivity test executed FROM the Iran router across each tunnel
 * transport (/30 peer ping with src-address pinning). Also reads interface
 * running state so «Running» on RouterOS is reflected in the panel.
 */
class TrafficTestService
{
    public function __construct(
        protected TunnelHealthProbeService $healthProbe,
    ) {
    }

    /**
     * @return array{tested_at: string, agents: list<array<string, mixed>>}
     */
    public function run(TunnelGroup $group): array
    {
        $group->loadMissing('iranServer', 'exits.server', 'exits.agents');
        $iran = $group->iranServer;
        $results = [];

        foreach ($group->exits as $exit) {
            foreach ($exit->agents as $agent) {
                $probe = $this->healthProbe->probeAgent($iran, $exit->server, $agent);

                $results[] = [
                    'agent_id' => $agent->id,
                    'interface' => $agent->iran_interface,
                    'target' => $agent->foreign_ip,
                    'exit' => $exit->server?->name,
                    'up' => $probe['up'],
                    'ping_up' => $probe['ping_up'],
                    'iran_running' => $probe['iran_running'],
                    'foreign_running' => $probe['foreign_running'],
                    'sent' => $probe['sent'],
                    'received' => $probe['received'],
                    'loss_pct' => $probe['loss_pct'],
                    'avg_rtt_ms' => $probe['avg_rtt_ms'],
                    'error' => $probe['error'],
                ];

                $agent->forceFill([
                    'health' => $probe['health'],
                    'last_seen_up_at' => $probe['up'] ? now() : $agent->last_seen_up_at,
                    'fail_count' => $probe['up'] ? 0 : $agent->fail_count + 1,
                ])->save();
            }
        }

        $report = ['tested_at' => now()->toIso8601String(), 'agents' => $results];

        $group->forceFill([
            'meta' => array_merge($group->meta ?? [], ['last_test' => $report]),
        ])->save();

        $up = collect($results)->where('up', true)->count();
        $total = count($results);

        TunnelGroupEvent::record(
            'traffic_test',
            "تست ترافیک گروه «{$group->name}»: {$up} از {$total} تانل سالم (ping یا running).",
            ['tunnel_group_id' => $group->id, 'detail' => ['agents' => $results]],
            $up === $total ? 'ok' : ($up > 0 ? 'warning' : 'error'),
        );

        return $report;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{sent: int, received: int, loss_pct: float, avg_rtt_ms: ?float}
     */
    public function summarize(array $rows): array
    {
        return $this->healthProbe->summarizePingRows($rows);
    }

    public function parseRouterOsTime(string $time): ?float
    {
        return $this->healthProbe->parseRouterOsTime($time);
    }
}
