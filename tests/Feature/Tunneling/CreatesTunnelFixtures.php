<?php

namespace Tests\Feature\Tunneling;

use App\Enums\ServerType;
use App\Enums\TunnelKind;
use App\Models\Server;
use App\Models\TunnelGroup;
use App\Models\TunnelGroupExit;

trait CreatesTunnelFixtures
{
    protected function makeServer(string $name, string $host): Server
    {
        return Server::create([
            'name' => $name,
            'type' => ServerType::Mikrotik->value,
            'host' => $host,
            'port' => 8728,
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<Server>  $exits
     */
    protected function makeGroup(Server $iran, array $exits, TunnelKind $kind = TunnelKind::Gre, array $overrides = []): TunnelGroup
    {
        $group = TunnelGroup::create(array_merge([
            'name' => 'tg-test',
            'kind' => $kind->value,
            'iran_server_id' => $iran->id,
            'agents_per_exit' => 1,
            'direction' => 'normal',
            'balancing_mode' => 'pcc',
            'ipsec_enabled' => false,
            'mss_clamp' => true,
            'auto_switch_l2tp' => false,
            'auto_switch_kind' => false,
            'status' => 'draft',
        ], $overrides));

        foreach (array_values($exits) as $index => $exit) {
            TunnelGroupExit::create([
                'tunnel_group_id' => $group->id,
                'server_id' => $exit->id,
                'position' => $index + 1,
                'status' => 'pending',
            ]);
        }

        return $group->fresh(['iranServer', 'exits.server', 'exits.agents']);
    }
}
