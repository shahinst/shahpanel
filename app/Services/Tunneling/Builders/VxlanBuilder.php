<?php

namespace App\Services\Tunneling\Builders;

use App\Models\Server;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;

/**
 * VXLAN point-to-point: an /interface/vxlan plus one static VTEP row pointing
 * at the peer on each side. UDP port participates in port hopping.
 */
class VxlanBuilder extends AbstractTunnelBuilder
{
    public function build(TunnelGroup $group, TunnelAgent $agent, Server $iran, Server $foreign): array
    {
        $mtu = $this->mtu($group);
        $vni = (string) ($agent->tunnel_id_value ?? $agent->id);
        $port = (string) ($agent->udp_port ?? 4789);

        $iface = fn (string $name): array => array_filter([
            'name' => $name,
            'vni' => $vni,
            'port' => $port,
            'mtu' => $mtu !== null ? (string) $mtu : null,
        ], fn ($v) => $v !== null);

        return [
            [
                'side' => 'iran',
                'object_type' => 'interface',
                'menu' => '/interface/vxlan',
                'key' => "a{$agent->id}:if-ir",
                'payload' => $iface($agent->iran_interface),
            ],
            [
                'side' => 'foreign',
                'object_type' => 'interface',
                'menu' => '/interface/vxlan',
                'key' => "a{$agent->id}:if-fr",
                'payload' => $iface($agent->foreign_interface),
            ],
            [
                'side' => 'iran',
                'object_type' => 'interface',
                'menu' => '/interface/vxlan/vteps',
                'key' => "a{$agent->id}:vtep-ir",
                'payload' => [
                    'interface' => $agent->iran_interface,
                    'remote-ip' => $foreign->apiConnectionHost(),
                    'port' => $port,
                ],
            ],
            [
                'side' => 'foreign',
                'object_type' => 'interface',
                'menu' => '/interface/vxlan/vteps',
                'key' => "a{$agent->id}:vtep-fr",
                'payload' => [
                    'interface' => $agent->foreign_interface,
                    'remote-ip' => $iran->apiConnectionHost(),
                    'port' => $port,
                ],
            ],
            ...$this->transportAddressSpecs($agent),
            ...$this->ipsecSpecs($group, $agent, $iran, $foreign, protocol: 'udp', port: (int) $port),
        ];
    }
}
