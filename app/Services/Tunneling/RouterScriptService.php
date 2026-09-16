<?php

namespace App\Services\Tunneling;

use App\Models\RouterScript;
use App\Models\Server;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;
use Illuminate\Support\Str;

/**
 * On-router monitoring: a RouterOS script + 10s scheduler that pings every
 * tunnel transport peer and POSTs results (with interface byte counters) back
 * to the panel report endpoint, authenticated by a per-server token.
 * Installation is checksum-based: identical script versions are not rewritten.
 */
class RouterScriptService
{
    public const SCRIPT_NAME = 'vpnl-probe';

    public function record(Server $server): RouterScript
    {
        return RouterScript::query()->firstOrCreate(
            ['server_id' => $server->id, 'name' => self::SCRIPT_NAME],
            [
                'version' => 0,
                'checksum' => '',
                'report_token' => Str::random(48),
                'status' => 'pending',
            ],
        );
    }

    /**
     * Build the probe script source for one router from every agent that
     * terminates on it (both Iran side and exit side).
     */
    public function buildScript(Server $server): string
    {
        $record = $this->record($server);
        $reportUrl = rtrim((string) config('app.url'), '/').'/tunneling/report';
        $interval = max(5, (int) config('tunneling.metrics.probe_interval', 10));

        $lines = [
            '# vpnl-probe — auto-generated, do not edit (managed by shahpanel)',
            ':local out ""',
        ];

        foreach ($this->agentsOn($server) as $info) {
            [$agentId, $iface, $peer] = $info;

            $lines[] = ':do {';
            $lines[] = "  :local rcv [/ping address={$peer} count=3 interval=0.2]";
            $lines[] = "  :local rxb [/interface get [find name=\"{$iface}\"] rx-byte]";
            $lines[] = "  :local txb [/interface get [find name=\"{$iface}\"] tx-byte]";
            $lines[] = "  :set out (\$out . \"{$agentId},\$rcv,3,\$rxb,\$txb;\")";
            $lines[] = "} on-error={ :set out (\$out . \"{$agentId},0,3,0,0;\") }";
        }

        $lines[] = ':if ([:len $out] > 0) do={';
        $lines[] = '  :do {';
        $lines[] = '    /tool fetch mode=https http-method=post keep-result=no \\';
        $lines[] = "      url=\"{$reportUrl}\" \\";
        $lines[] = "      http-data=(\"token={$record->report_token}&server={$server->id}&data=\" . \$out)";
        $lines[] = '  } on-error={}';
        $lines[] = '}';

        return implode("\n", $lines)."\n# interval={$interval}s\n";
    }

    public function checksum(string $script): string
    {
        return hash('sha256', $script);
    }

    /**
     * Agent rows terminating on this server: [agentId, local interface, peer ip].
     *
     * @return list<array{0: int, 1: string, 2: string}>
     */
    public function agentsOn(Server $server): array
    {
        $result = [];

        $iranAgents = TunnelAgent::query()
            ->whereHas('group', function ($q) use ($server): void {
                $q->where('iran_server_id', $server->id)
                    ->whereIn('status', ['active', 'applying', 'degraded']);
            })
            ->get();

        foreach ($iranAgents as $agent) {
            $result[] = [$agent->id, $agent->iran_interface, $agent->foreign_ip];
        }

        $exitAgents = TunnelAgent::query()
            ->whereHas('exit', fn ($q) => $q->where('server_id', $server->id))
            ->whereHas('group', fn ($q) => $q->whereIn('status', ['active', 'applying', 'degraded']))
            ->get();

        foreach ($exitAgents as $agent) {
            $result[] = [$agent->id, $agent->foreign_interface, $agent->iran_ip];
        }

        return $result;
    }

    /**
     * Servers that should carry the probe script (any active tunnel group).
     *
     * @return list<int>
     */
    public function serverIdsNeedingScript(): array
    {
        $groups = TunnelGroup::query()
            ->whereIn('status', ['active', 'applying', 'degraded'])
            ->with('exits')
            ->get();

        return $groups
            ->flatMap(fn (TunnelGroup $g) => [$g->iran_server_id, ...$g->exits->pluck('server_id')])
            ->unique()
            ->values()
            ->all();
    }
}
