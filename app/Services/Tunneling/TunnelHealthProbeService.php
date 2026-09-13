<?php

namespace App\Services\Tunneling;

use App\Enums\AgentHealth;
use App\Models\Server;
use App\Models\TunnelAgent;
use App\Services\MikrotikService;
use Throwable;

/**
 * Real tunnel health: transport ping + RouterOS interface running state.
 */
class TunnelHealthProbeService
{
    public function __construct(protected MikrotikService $mikrotik)
    {
    }

    /**
     * @return array{
     *     up: bool,
     *     health: AgentHealth,
     *     ping_up: bool,
     *     iran_running: bool,
     *     foreign_running: bool,
     *     sent: int,
     *     received: int,
     *     loss_pct: float,
     *     avg_rtt_ms: ?float,
     *     error: ?string
     * }
     */
    public function probeAgent(Server $iran, ?Server $foreign, TunnelAgent $agent): array
    {
        $base = [
            'ping_up' => false,
            'iran_running' => $this->interfaceIsRunning($iran, $agent->iran_interface),
            'foreign_running' => $foreign !== null
                ? $this->interfaceIsRunning($foreign, $agent->foreign_interface)
                : false,
            'sent' => 0,
            'received' => 0,
            'loss_pct' => 100.0,
            'avg_rtt_ms' => null,
            'error' => null,
        ];

        try {
            $rows = $this->mikrotik->ping($iran, [
                'address' => $agent->foreign_ip,
                'src-address' => $agent->iran_ip,
                'count' => '5',
                'interval' => '0.5s',
            ]);
            $summary = $this->summarizePingRows($rows);
            $base['sent'] = $summary['sent'];
            $base['received'] = $summary['received'];
            $base['loss_pct'] = $summary['loss_pct'];
            $base['avg_rtt_ms'] = $summary['avg_rtt_ms'];
            $base['ping_up'] = $summary['received'] > 0;
        } catch (Throwable $e) {
            $base['error'] = $e->getMessage();
        }

        $interfacesUp = $base['iran_running'] && $base['foreign_running'];

        $health = match (true) {
            $base['ping_up'] => AgentHealth::Up,
            $interfacesUp => AgentHealth::Degraded,
            default => AgentHealth::Down,
        };

        return $base + [
            'up' => $health !== AgentHealth::Down,
            'health' => $health,
        ];
    }

    public function interfaceIsRunning(Server $server, string $interfaceName): bool
    {
        if ($interfaceName === '') {
            return false;
        }

        try {
            $rows = $this->mikrotik->queryRouter($server, '/interface/print', ['name' => $interfaceName]);
        } catch (Throwable) {
            return false;
        }

        $row = $rows[0] ?? null;

        if (! is_array($row)) {
            return false;
        }

        $running = strtolower((string) ($row['running'] ?? ''));
        $disabled = strtolower((string) ($row['disabled'] ?? ''));

        return in_array($running, ['true', 'yes'], true)
            && ! in_array($disabled, ['true', 'yes'], true);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{sent: int, received: int, loss_pct: float, avg_rtt_ms: ?float}
     */
    public function summarizePingRows(array $rows): array
    {
        $sent = 0;
        $received = 0;
        $rtts = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (isset($row['sent'])) {
                $sent = max($sent, (int) $row['sent']);
            }

            if (isset($row['received'])) {
                $received = max($received, (int) $row['received']);
            }

            $status = strtolower((string) ($row['status'] ?? ''));

            if ($status === 'timeout') {
                continue;
            }

            if (isset($row['time']) && $status !== 'timeout') {
                $ms = $this->parseRouterOsTime((string) $row['time']);

                if ($ms !== null) {
                    $rtts[] = $ms;
                    $received = max($received, count($rtts));
                }
            }
        }

        if ($sent === 0) {
            $sent = max(count($rtts), count(array_filter(
                $rows,
                fn ($r) => is_array($r) && isset($r['seq']) && strtolower((string) ($r['status'] ?? '')) !== 'timeout',
            )));
        }

        if ($received === 0 && $rtts !== []) {
            $received = count($rtts);
        }

        return [
            'sent' => $sent,
            'received' => $received,
            'loss_pct' => $sent > 0 ? round((1 - $received / $sent) * 100, 1) : 100.0,
            'avg_rtt_ms' => $rtts !== [] ? round(array_sum($rtts) / count($rtts), 1) : null,
        ];
    }

    public function parseRouterOsTime(string $time): ?float
    {
        if (! preg_match_all('/(\d+)(s|ms|us)/', $time, $matches, PREG_SET_ORDER)) {
            return is_numeric($time) ? (float) $time : null;
        }

        $ms = 0.0;

        foreach ($matches as [, $value, $unit]) {
            $ms += match ($unit) {
                's' => (float) $value * 1000,
                'ms' => (float) $value,
                'us' => (float) $value / 1000,
            };
        }

        return $ms;
    }
}
