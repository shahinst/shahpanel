<?php

namespace App\Services\Tunneling\Builders;

use App\Enums\TunnelDirection;
use App\Models\Server;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;

/**
 * L2TPv3 static ethernet pseudowire (/interface/l2tp-ether, RouterOS ≥ 7.16)
 * in UDP or raw-IP encapsulation. Symmetric session: both sides configure the
 * same tunnel/session IDs and the panel-issued circuit-id. Direction only
 * matters behind NAT: the dialing side sets connect-to, the listening side
 * still configures connect-to for static sessions (RouterOS requirement).
 */
class L2tpV3Builder extends AbstractTunnelBuilder
{
    public function __construct(protected string $transportProto = 'udp')
    {
    }

    public function build(TunnelGroup $group, TunnelAgent $agent, Server $iran, Server $foreign): array
    {
        $mtu = $this->mtu($group);
        $tunnelId = (string) ($agent->tunnel_id_value ?? $agent->id);
        $circuit = (string) ($group->circuit_id ?: 'vpnl');

        $iface = fn (string $name, string $connectTo): array => array_filter([
            'name' => $name,
            'connect-to' => $connectTo,
            'transport-proto' => $this->transportProto,
            'tunnel-id' => $tunnelId,
            'peer-tunnel-id' => $tunnelId,
            'session-id' => $tunnelId,
            'peer-session-id' => $tunnelId,
            'circuit-id' => $circuit,
            'mtu' => $mtu !== null ? (string) $mtu : null,
        ], fn ($v) => $v !== null);

        $reverse = $group->direction === TunnelDirection::Reverse;

        return [
            [
                'side' => 'iran',
                'object_type' => 'interface',
                'menu' => '/interface/l2tp-ether',
                'key' => "a{$agent->id}:if-ir",
                'payload' => $iface($agent->iran_interface, $foreign->apiConnectionHost()),
            ],
            [
                'side' => 'foreign',
                'object_type' => 'interface',
                'menu' => '/interface/l2tp-ether',
                'key' => "a{$agent->id}:if-fr",
                'payload' => $iface($agent->foreign_interface, $iran->apiConnectionHost()),
            ],
            ...$this->transportAddressSpecs($agent),
            ...$this->ipsecSpecs(
                $group,
                $agent,
                $iran,
                $foreign,
                protocol: $this->transportProto === 'udp' ? 'udp' : null,
                port: $this->transportProto === 'udp' ? 1701 : null,
            ),
        ];
    }
}
