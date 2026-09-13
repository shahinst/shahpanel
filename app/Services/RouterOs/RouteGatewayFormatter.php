<?php

namespace App\Services\RouterOs;

use App\Models\TunnelAgent;

/**
 * Gateway strings for /ip/route on RouterOS tunnel /30 peers.
 *
 * Use the peer IP only — IP%interface fails with "invalid interface value" when
 * the interface row is missing or not yet committed (common after partial apply).
 */
final class RouteGatewayFormatter
{
    public static function peerGateway(string $peerIp): string
    {
        return trim($peerIp);
    }

    /** @deprecated Use peerGateway(); kept for call-site clarity. */
    public static function onLink(string $peerIp, string $interface): string
    {
        return self::peerGateway($peerIp);
    }

    /**
     * @param  list<TunnelAgent>  $agents
     */
    public static function weightedOnLink(array $agents, callable $peerIpFor, callable $interfaceFor): string
    {
        unset($interfaceFor);

        $gateways = [];

        foreach ($agents as $agent) {
            $peerIp = self::peerGateway((string) $peerIpFor($agent));

            if ($peerIp === '') {
                continue;
            }

            $repeat = min(4, max(1, $agent->weight));

            for ($i = 0; $i < $repeat; $i++) {
                $gateways[] = $peerIp;
            }
        }

        return implode(',', $gateways);
    }
}
