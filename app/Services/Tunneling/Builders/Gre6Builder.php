<?php

namespace App\Services\Tunneling\Builders;

use App\Models\Server;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;
use RuntimeException;

/**
 * GRE over IPv6 underlay. Requires both endpoints to have global IPv6
 * addresses, stored on the agent meta (ipv6_iran / ipv6_foreign) by the
 * orchestrator from group/exit settings.
 */
class Gre6Builder extends AbstractTunnelBuilder
{
    public function build(TunnelGroup $group, TunnelAgent $agent, Server $iran, Server $foreign): array
    {
        $iranV6 = (string) ($agent->meta['ipv6_iran'] ?? '');
        $foreignV6 = (string) ($agent->meta['ipv6_foreign'] ?? '');

        if ($iranV6 === '' || $foreignV6 === '') {
            throw new RuntimeException(
                __('services.tunnel_gre6_needs_ipv6', ['id' => $agent->id])
            );
        }

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
                'menu' => '/interface/gre6',
                'key' => "a{$agent->id}:if-ir",
                'payload' => $iface($agent->iran_interface, $iranV6, $foreignV6),
            ],
            [
                'side' => 'foreign',
                'object_type' => 'interface',
                'menu' => '/interface/gre6',
                'key' => "a{$agent->id}:if-fr",
                'payload' => $iface($agent->foreign_interface, $foreignV6, $iranV6),
            ],
            ...$this->transportAddressSpecs($agent),
        ];
    }
}
