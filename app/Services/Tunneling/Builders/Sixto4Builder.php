<?php

namespace App\Services\Tunneling\Builders;

use App\Models\Server;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;

/**
 * RouterOS native 6to4 (IPv6-in-IPv4, protocol 41) point-to-point tunnel.
 * Mirrors IpipBuilder's structure — same transport /30 + optional IPsec
 * transport-mode protection, but a dedicated /interface/6to4 menu and
 * explicit local-address (RouterOS 6to4 requires it, unlike IPIP/GRE which
 * can infer the local endpoint from the default route).
 */
class Sixto4Builder extends AbstractTunnelBuilder
{
    public function build(TunnelGroup $group, TunnelAgent $agent, Server $iran, Server $foreign): array
    {
        $mtu = $this->mtu($group);

        $iface = fn (string $name, string $local, string $remote): array => array_filter([
            'name' => $name,
            'local-address' => $local,
            'remote-address' => $remote,
            'mtu' => $mtu !== null ? (string) $mtu : null,
        ], fn ($v) => $v !== null);

        return [
            [
                'side' => 'iran',
                'object_type' => 'interface',
                'menu' => '/interface/6to4',
                'key' => "a{$agent->id}:if-ir",
                'payload' => $iface($agent->iran_interface, $iran->apiConnectionHost(), $foreign->apiConnectionHost()),
            ],
            [
                'side' => 'foreign',
                'object_type' => 'interface',
                'menu' => '/interface/6to4',
                'key' => "a{$agent->id}:if-fr",
                'payload' => $iface($agent->foreign_interface, $foreign->apiConnectionHost(), $iran->apiConnectionHost()),
            ],
            ...$this->transportAddressSpecs($agent),
            ...$this->ipsecSpecs($group, $agent, $iran, $foreign, protocol: 'ipv6'),
        ];
    }
}
