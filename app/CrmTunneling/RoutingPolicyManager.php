<?php

namespace App\CrmTunneling;

use App\Models\CrmTunnel;
use App\Models\Server;

/**
 * Routing / load-balance layer (skeleton — full PCC/failover in a later phase).
 */
class RoutingPolicyManager
{
    public function __construct(protected MikrotikClient $client)
    {
    }

    /**
     * Rebuild hub routing tables + default routes for all active CRM tunnels.
     *
     * TODO: client subnet mangle marks + backup routes with distance/check-gateway.
     */
    public function rebuild(Server $hub): array
    {
        $prefix = (string) config('crm_tunnel.comment_prefix', 'CRM-TUN');
        $logs = ["rebuild routing on {$hub->name}"];

        $this->client->removeWhereCommentContains($hub, '/routing/table', $prefix.'-RT');
        $this->client->removeWhereCommentContains($hub, '/ip/route', $prefix.'-RT');

        $tunnels = CrmTunnel::query()
            ->where('hub_server_id', $hub->id)
            ->where('status', 'active')
            ->get();

        foreach ($tunnels as $tunnel) {
            $table = "via-{$tunnel->id}";
            $comment = "{$prefix}-RT-{$tunnel->id}";

            $this->client->add($hub, '/routing/table', [
                'name' => $table,
                'fib' => '',
                'comment' => $comment,
            ]);

            $this->client->add($hub, '/ip/route', [
                'dst-address' => '0.0.0.0/0',
                'gateway' => $tunnel->exit_tunnel_ip,
                'routing-table' => $table,
                'check-gateway' => 'ping',
                'distance' => '1',
                'comment' => $comment,
            ]);

            $logs[] = "  table {$table} → {$tunnel->exit_tunnel_ip}";
        }

        return $logs;
    }
}
