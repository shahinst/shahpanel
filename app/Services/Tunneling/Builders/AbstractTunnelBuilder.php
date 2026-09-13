<?php

namespace App\Services\Tunneling\Builders;

use App\Models\Server;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;

abstract class AbstractTunnelBuilder implements TunnelBuilder
{
    /** Tunnel interface MTU for this group (probed wins over calculated). */
    protected function mtu(TunnelGroup $group): ?int
    {
        return $group->effectiveMtu();
    }

    /**
     * Transport /30 addresses on the tunnel interfaces of both sides.
     *
     * @return list<array{side: string, object_type: string, menu: string, key: string, payload: array<string, mixed>}>
     */
    protected function transportAddressSpecs(TunnelAgent $agent): array
    {
        return [
            [
                'side' => 'iran',
                'object_type' => 'ip_address',
                'menu' => '/ip/address',
                'key' => "a{$agent->id}:ip-ir",
                'payload' => [
                    'address' => $agent->iran_ip.'/30',
                    'interface' => $agent->iran_interface,
                ],
            ],
            [
                'side' => 'foreign',
                'object_type' => 'ip_address',
                'menu' => '/ip/address',
                'key' => "a{$agent->id}:ip-fr",
                'payload' => [
                    'address' => $agent->foreign_ip.'/30',
                    'interface' => $agent->foreign_interface,
                ],
            ],
        ];
    }

    /**
     * IPsec transport-mode protection for UDP/IP-encapsulated kinds
     * (peer + identity + policy on both sides).
     *
     * @return list<array{side: string, object_type: string, menu: string, key: string, payload: array<string, mixed>}>
     */
    protected function ipsecSpecs(
        TunnelGroup $group,
        TunnelAgent $agent,
        Server $iran,
        Server $foreign,
        ?string $protocol = null,
        ?int $port = null,
    ): array {
        if (! $group->ipsec_enabled || (string) $group->ipsec_secret_enc === '') {
            return [];
        }

        $secret = (string) $group->ipsec_secret_enc;
        $iranIp = $iran->apiConnectionHost();
        $foreignIp = $foreign->apiConnectionHost();
        $specs = [];

        foreach ([
            'iran' => ['peer' => $foreignIp, 'suffix' => 'ir'],
            'foreign' => ['peer' => $iranIp, 'suffix' => 'fr'],
        ] as $side => $info) {
            $peerName = "vpnl-tg{$group->id}-a{$agent->id}-{$info['suffix']}";

            $specs[] = [
                'side' => $side,
                'object_type' => 'ipsec',
                'menu' => '/ip/ipsec/peer',
                'key' => "a{$agent->id}:ipsec-peer-{$info['suffix']}",
                'payload' => [
                    'name' => $peerName,
                    'address' => $info['peer'].'/32',
                    'exchange-mode' => 'ike2',
                ],
            ];

            $specs[] = [
                'side' => $side,
                'object_type' => 'ipsec',
                'menu' => '/ip/ipsec/identity',
                'key' => "a{$agent->id}:ipsec-id-{$info['suffix']}",
                'payload' => [
                    'peer' => $peerName,
                    'auth-method' => 'pre-shared-key',
                    'secret' => $secret,
                ],
            ];

            $policy = [
                'peer' => $peerName,
                'tunnel' => 'no',
                'src-address' => ($side === 'iran' ? $iranIp : $foreignIp).'/32',
                'dst-address' => $info['peer'].'/32',
                'action' => 'encrypt',
                'level' => 'require',
            ];

            if ($protocol !== null) {
                $policy['protocol'] = $protocol;
            }

            if ($port !== null) {
                $policy['dst-port'] = (string) $port;
                $policy['src-port'] = (string) $port;
            }

            $specs[] = [
                'side' => $side,
                'object_type' => 'ipsec',
                'menu' => '/ip/ipsec/policy',
                'key' => "a{$agent->id}:ipsec-pol-{$info['suffix']}",
                'payload' => $policy,
            ];
        }

        return $specs;
    }
}
