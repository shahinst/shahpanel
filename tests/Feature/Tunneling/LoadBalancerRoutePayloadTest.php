<?php

namespace Tests\Feature\Tunneling;

use App\Enums\BalancingMode;
use App\Models\ManagedInterface;
use App\Services\Tunneling\LoadBalancerService;
use App\Services\Tunneling\TunnelGroupOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LoadBalancerRoutePayloadTest extends TestCase
{
    use CreatesTunnelFixtures;
    use RefreshDatabase;

    public function test_route_gateways_use_on_link_interface_syntax(): void
    {
        Queue::fake();

        $iran = $this->makeServer('iran', '10.0.0.1');
        $foreign = $this->makeServer('tr', '10.0.0.2');
        $group = $this->makeGroup($iran, [$foreign], overrides: [
            'balancing_mode' => BalancingMode::Ecmp->value,
        ]);

        app(TunnelGroupOrchestrator::class)->syncAgents($group);

        ManagedInterface::create([
            'server_id' => $iran->id,
            'tunnel_group_id' => $group->id,
            'name' => 'wg-clients',
            'type' => 'wireguard',
            'subnet' => '10.64.1.0/24',
        ]);

        $group = $group->fresh(['iranServer', 'exits.server', 'exits.agents']);
        $specs = app(LoadBalancerService::class)->desiredObjects($group);

        $defaultRoute = collect($specs)->first(fn (array $spec): bool => $spec['key'] === 'default');
        $returnRoute = collect($specs)->first(
            fn (array $spec): bool => str_starts_with((string) $spec['key'], 'e1:return:'),
        );
        $routingTable = collect($specs)->first(fn (array $spec): bool => $spec['key'] === 'rt');

        $this->assertNotNull($defaultRoute);
        $this->assertNotNull($returnRoute);
        $this->assertSame('172.16.0.2', $defaultRoute['payload']['gateway']);
        $this->assertSame('172.16.0.1', $returnRoute['payload']['gateway']);
        $this->assertArrayHasKey('fib', $routingTable['payload']);
    }
}
