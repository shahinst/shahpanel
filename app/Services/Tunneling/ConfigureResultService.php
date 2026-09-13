<?php

namespace App\Services\Tunneling;

use App\Models\ManagedInterface;
use App\Models\TunnelGroup;
use App\Models\TunnelGroupEvent;

/**
 * Builds a structured report after «کانفیگ و تست» for the admin UI.
 */
class ConfigureResultService
{
    /**
     * @return array<string, mixed>
     */
    public function build(TunnelGroup $group): array
    {
        $group->loadMissing('iranServer', 'exits.server', 'exits.agents', 'location');

        $meta = $group->meta ?? [];
        $apply = $meta['last_apply'] ?? [];
        $mtuProbe = $meta['mtu_probe'] ?? [];
        $traffic = $meta['last_test'] ?? [];

        $agents = $group->exits->flatMap->agents;

        $managedWg = ManagedInterface::query()
            ->where('tunnel_group_id', $group->id)
            ->where('type', 'wireguard')
            ->get(['id', 'name', 'subnet', 'listen_port', 'status']);

        $agentRows = $agents->map(fn ($agent) => [
            'seq' => $agent->seq,
            'kind' => $agent->kind->label(),
            'exit' => $agent->exit?->server?->name,
            'iran_interface' => $agent->iran_interface,
            'foreign_interface' => $agent->foreign_interface,
            'transport' => $agent->iran_ip.' ↔ '.$agent->foreign_ip,
            'health' => $agent->health->value,
            'up' => $agent->health->value !== 'down',
        ])->values()->all();

        $upCount = collect($traffic['agents'] ?? [])->where('up', true)->count();
        $totalAgents = count($traffic['agents'] ?? []) ?: $agents->count();

        $report = [
            'finished_at' => now()->toIso8601String(),
            'group_status' => $group->status->value,
            'status_message' => $group->status_message,
            'summary' => [
                'agents' => $agents->count(),
                'kinds' => $group->kindMixLabels(),
                'balancing' => $group->balancing_mode->value,
                'mtu_effective' => $group->effectiveMtu(),
                'traffic_up' => $upCount,
                'traffic_total' => $totalAgents,
            ],
            'wireguard_interfaces' => $managedWg->map(fn ($wg) => [
                'name' => $wg->name,
                'subnet' => $wg->subnet,
                'port' => $wg->listen_port,
                'status' => $wg->status,
            ])->all(),
            'apply' => $apply,
            'mtu_probe' => $mtuProbe,
            'traffic_test' => $traffic,
            'agents' => $agentRows,
            'success' => $group->status->value === 'active'
                && ($totalAgents === 0 || $upCount === $totalAgents)
                && ((int) ($apply['failed'] ?? 0)) === 0,
        ];

        $group->forceFill([
            'meta' => array_merge($meta, ['configure_result' => $report]),
        ])->save();

        $summary = sprintf(
            'کانفیگ «%s»: %d agent، MTU %s، ترافیک %d/%d، اعمال %d خطا.',
            $group->name,
            $agents->count(),
            $group->effectiveMtu() ?? '—',
            $upCount,
            max(1, $totalAgents),
            (int) ($apply['failed'] ?? 0),
        );

        TunnelGroupEvent::record(
            'configure_result',
            $summary,
            ['tunnel_group_id' => $group->id, 'detail' => $report],
            $report['success'] ? 'ok' : 'warning',
        );

        return $report;
    }
}
