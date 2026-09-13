<?php

namespace App\Services\Tunneling\Builders;

use App\Models\Server;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;

class EoipBuilder extends AbstractTunnelBuilder
{
    public function build(TunnelGroup $group, TunnelAgent $agent, Server $iran, Server $foreign): array
    {
        $mtu = $this->mtu($group);
        $tunnelId = (string) ($agent->tunnel_id_value ?? $agent->id);

        $iface = fn (string $name, string $remote): array => array_filter([
            'name' => $name,
            'remote-address' => $remote,
            'tunnel-id' => $tunnelId,
            'keepalive' => '10s,3',
            'allow-fast-path' => 'yes',
            'mtu' => $mtu !== null ? (string) $mtu : null,
        ], fn ($v) => $v !== null);

        return [
            [
                'side' => 'iran',
                'object_type' => 'interface',
                'menu' => '/interface/eoip',
                'key' => "a{$agent->id}:if-ir",
                'payload' => $iface($agent->iran_interface, $foreign->apiConnectionHost()),
            ],
            [
                'side' => 'foreign',
                'object_type' => 'interface',
                'menu' => '/interface/eoip',
                'key' => "a{$agent->id}:if-fr",
                'payload' => $iface($agent->foreign_interface, $iran->apiConnectionHost()),
            ],
            ...$this->transportAddressSpecs($agent),
            ...$this->ipsecSpecs($group, $agent, $iran, $foreign, protocol: 'gre'),
        ];
    }
}
