<?php

namespace Tests\Unit\Tunneling;

use App\Models\TunnelAgent;
use App\Services\RouterOs\RouteGatewayFormatter;
use PHPUnit\Framework\TestCase;

class RouteGatewayFormatterTest extends TestCase
{
    public function test_peer_gateway_is_ip_only(): void
    {
        $this->assertSame(
            '172.16.0.2',
            RouteGatewayFormatter::peerGateway('172.16.0.2'),
        );
        $this->assertSame(
            '172.16.0.2',
            RouteGatewayFormatter::onLink('172.16.0.2', 'vpnl-tg1-e1-a1'),
        );
    }

    public function test_weighted_gateways_repeat_by_weight(): void
    {
        $agent = new TunnelAgent(['weight' => 2]);

        $gateway = RouteGatewayFormatter::weightedOnLink(
            [$agent],
            fn (): string => '10.0.0.1',
            fn (): string => 'ignored',
        );

        $this->assertSame('10.0.0.1,10.0.0.1', $gateway);
    }
}
