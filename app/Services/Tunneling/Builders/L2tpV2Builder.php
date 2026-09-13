<?php

namespace App\Services\Tunneling\Builders;

use App\Enums\TunnelDirection;
use App\Models\Server;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;

/**
 * Classic L2TPv2 + IPsec. Direction decides who dials: normal = Iran client →
 * foreign server; reverse = foreign client → Iran server (for networks where
 * outbound from Iran is throttled but inbound is clean).
 *
 * Transport IPs are assigned through the PPP secret (local/remote-address) and
 * a static server binding keeps the server-side interface name deterministic
 * so routes/balancing can reference it.
 */
class L2tpV2Builder extends AbstractTunnelBuilder
{
    public function build(TunnelGroup $group, TunnelAgent $agent, Server $iran, Server $foreign): array
    {
        $reverse = $group->direction === TunnelDirection::Reverse;

        // Sides by role.
        $serverSide = $reverse ? 'iran' : 'foreign';
        $clientSide = $reverse ? 'foreign' : 'iran';
        $serverHost = $reverse ? $iran : $foreign;
        $serverIp = $reverse ? $agent->iran_ip : $agent->foreign_ip;
        $clientIp = $reverse ? $agent->foreign_ip : $agent->iran_ip;
        $serverIface = $reverse ? $agent->iran_interface : $agent->foreign_interface;
        $clientIface = $reverse ? $agent->foreign_interface : $agent->iran_interface;

        $user = "vpnl-tg{$group->id}-a{$agent->id}";
        $password = (string) ($agent->meta['l2tp_password'] ?? '');
        $useIpsec = $group->ipsec_enabled && (string) $group->ipsec_secret_enc !== '';

        $specs = [];

        // Server side: enable the L2TP server (singleton) once per group.
        $specs[] = [
            'side' => $serverSide,
            'object_type' => 'singleton',
            'menu' => '/interface/l2tp-server/server',
            'key' => 'l2tp-server',
            'payload' => array_filter([
                'enabled' => 'yes',
                'use-ipsec' => $useIpsec ? 'yes' : null,
                'ipsec-secret' => $useIpsec ? (string) $group->ipsec_secret_enc : null,
                'keepalive-timeout' => '30',
            ], fn ($v) => $v !== null),
        ];

        // PPP secret: hands the /30 pair to the dial-up session.
        $specs[] = [
            'side' => $serverSide,
            'object_type' => 'interface',
            'menu' => '/ppp/secret',
            'key' => "a{$agent->id}:secret",
            'payload' => [
                'name' => $user,
                'password' => $password,
                'service' => 'l2tp',
                'local-address' => $serverIp,
                'remote-address' => $clientIp,
            ],
        ];

        // Static binding: deterministic server-side interface name.
        $specs[] = [
            'side' => $serverSide,
            'object_type' => 'interface',
            'menu' => '/interface/l2tp-server',
            'key' => "a{$agent->id}:binding",
            'payload' => [
                'name' => $serverIface,
                'user' => $user,
            ],
        ];

        // Client side dials the server's public address.
        $specs[] = [
            'side' => $clientSide,
            'object_type' => 'interface',
            'menu' => '/interface/l2tp-client',
            'key' => "a{$agent->id}:client",
            'payload' => array_filter([
                'name' => $clientIface,
                'connect-to' => $serverHost->apiConnectionHost(),
                'user' => $user,
                'password' => $password,
                'add-default-route' => 'no',
                'keepalive-timeout' => '30',
                'use-ipsec' => $useIpsec ? 'yes' : null,
                'ipsec-secret' => $useIpsec ? (string) $group->ipsec_secret_enc : null,
                'disabled' => 'no',
            ], fn ($v) => $v !== null),
        ];

        return $specs;
    }
}
