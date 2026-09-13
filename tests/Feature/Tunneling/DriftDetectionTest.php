<?php

namespace Tests\Feature\Tunneling;

use App\Enums\DesiredObjectStatus;
use App\Models\DesiredNetworkObject;
use App\Models\Server;
use App\Services\MikrotikService;
use App\Services\RouterOs\DesiredStateApplier;
use App\Services\RouterOs\RouterCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DriftDetectionTest extends TestCase
{
    use CreatesTunnelFixtures;
    use RefreshDatabase;

    protected function desiredRoute(Server $server, string $marker = 'vpnl:tg1:test-route'): DesiredNetworkObject
    {
        return DesiredNetworkObject::create([
            'server_id' => $server->id,
            'object_type' => 'route',
            'menu' => '/ip/route',
            'marker' => $marker,
            'payload' => ['dst-address' => '0.0.0.0/0', 'gateway' => '172.16.0.2'],
            'status' => DesiredObjectStatus::Applied,
        ]);
    }

    public function test_ensure_creates_when_marker_missing(): void
    {
        $server = $this->makeServer('iran', '10.0.0.1');
        $object = $this->desiredRoute($server);

        $mikrotik = $this->mock(MikrotikService::class, function (MockInterface $mock) use ($object): void {
            $mock->shouldReceive('queryRouter')
                ->once()
                ->with(Mockery::type(Server::class), '/ip/route/print', ['comment' => $object->marker])
                ->andReturn([]);
            $mock->shouldReceive('sendCommand')
                ->once()
                ->withArgs(fn ($srv, string $cmd, array $args): bool => $cmd === '/ip/route/add'
                    && $args['comment'] === $object->marker
                    && $args['gateway'] === '172.16.0.2')
                ->andReturn([]);
        });

        $result = (new RouterCommandService($mikrotik))->ensure($server, $object);

        $this->assertSame(RouterCommandService::RESULT_CREATED, $result);
    }

    public function test_ensure_is_noop_when_actual_matches(): void
    {
        $server = $this->makeServer('iran', '10.0.0.1');
        $object = $this->desiredRoute($server);

        $mikrotik = $this->mock(MikrotikService::class, function (MockInterface $mock) use ($object): void {
            $mock->shouldReceive('queryRouter')
                ->once()
                ->andReturn([[
                    '.id' => '*1',
                    'dst-address' => '0.0.0.0/0',
                    'gateway' => '172.16.0.2',
                    'comment' => $object->marker,
                ]]);
            $mock->shouldNotReceive('sendCommand');
        });

        $result = (new RouterCommandService($mikrotik))->ensure($server, $object);

        $this->assertSame(RouterCommandService::RESULT_UNCHANGED, $result);
    }

    public function test_detect_drift_flags_changed_router_row(): void
    {
        $server = $this->makeServer('iran', '10.0.0.1');
        $object = $this->desiredRoute($server);

        $mikrotik = $this->mock(MikrotikService::class, function (MockInterface $mock): void {
            // Someone changed the gateway on the router by hand.
            $mock->shouldReceive('queryRouter')->andReturn([[
                '.id' => '*1',
                'dst-address' => '0.0.0.0/0',
                'gateway' => '192.168.99.99',
            ]]);
        });

        $applier = app(DesiredStateApplier::class);
        $drifted = $applier->detectDrift($server);

        $this->assertCount(1, $drifted);
        $this->assertSame(DesiredObjectStatus::Drift, $object->fresh()->status);
    }

    public function test_detect_drift_flags_missing_router_row(): void
    {
        $server = $this->makeServer('iran', '10.0.0.1');
        $object = $this->desiredRoute($server);

        $mikrotik = $this->mock(MikrotikService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('queryRouter')->andReturn([]);
        });

        $applier = app(DesiredStateApplier::class);

        $this->assertCount(1, $applier->detectDrift($server));
        $this->assertSame(DesiredObjectStatus::Drift, $object->fresh()->status);
    }

    public function test_detect_drift_passes_matching_row(): void
    {
        $server = $this->makeServer('iran', '10.0.0.1');
        $object = $this->desiredRoute($server);

        $mikrotik = $this->mock(MikrotikService::class, function (MockInterface $mock) use ($object): void {
            $mock->shouldReceive('queryRouter')->andReturn([[
                '.id' => '*1',
                'dst-address' => '0.0.0.0/0',
                'gateway' => '172.16.0.2',
                'comment' => $object->marker,
            ]]);
        });

        $applier = app(DesiredStateApplier::class);

        $this->assertCount(0, $applier->detectDrift($server));
        $this->assertSame(DesiredObjectStatus::Applied, $object->fresh()->status);
        $this->assertNotNull($object->fresh()->last_verified_at);
    }

    public function test_duplicate_marker_rows_are_pruned(): void
    {
        $server = $this->makeServer('iran', '10.0.0.1');
        $object = $this->desiredRoute($server);

        $mikrotik = $this->mock(MikrotikService::class, function (MockInterface $mock) use ($object): void {
            $mock->shouldReceive('queryRouter')->once()->andReturn([
                ['.id' => '*1', 'dst-address' => '0.0.0.0/0', 'gateway' => '172.16.0.2', 'comment' => $object->marker],
                ['.id' => '*2', 'dst-address' => '0.0.0.0/0', 'gateway' => '172.16.0.2', 'comment' => $object->marker],
            ]);
            $mock->shouldReceive('sendCommand')
                ->once()
                ->with(Mockery::type(Server::class), '/ip/route/remove', ['.id' => '*2'])
                ->andReturn([]);
        });

        $result = (new RouterCommandService($mikrotik))->ensure($server, $object);

        $this->assertSame(RouterCommandService::RESULT_UNCHANGED, $result);
    }

    public function test_ensure_removes_stale_gre_before_creating_ipip_with_same_name(): void
    {
        $server = $this->makeServer('iran', '10.0.0.1');
        $marker = 'vpnl:tg1:a1:if-ir';
        $object = DesiredNetworkObject::create([
            'server_id' => $server->id,
            'object_type' => 'interface',
            'menu' => '/interface/ipip',
            'marker' => $marker,
            'payload' => [
                'name' => 'vpnl-tg1-e1-a1',
                'remote-address' => '203.0.113.2',
            ],
            'status' => DesiredObjectStatus::Pending,
        ]);

        $removedGre = false;
        $createdIpip = false;

        $mikrotik = $this->mock(MikrotikService::class, function (MockInterface $mock) use ($marker, &$removedGre, &$createdIpip): void {
            $mock->shouldReceive('queryRouter')
                ->andReturnUsing(function (Server $server, string $path, array $filters = []) use ($marker): array {
                    if ($path === '/interface/ipip/print' && ($filters['comment'] ?? null) === $marker) {
                        return [];
                    }

                    if ($path === '/interface/gre/print' && ($filters['comment'] ?? null) === $marker) {
                        return [['.id' => '*gre1', 'name' => 'vpnl-tg1-e1-a1', 'comment' => $marker]];
                    }

                    return [];
                });

            $mock->shouldReceive('sendCommand')
                ->andReturnUsing(function (Server $server, string $path, array $args = []) use (&$removedGre, &$createdIpip, $marker): array {
                    if ($path === '/interface/gre/remove' && ($args['.id'] ?? null) === '*gre1') {
                        $removedGre = true;
                    }

                    if ($path === '/interface/ipip/add'
                        && ($args['name'] ?? null) === 'vpnl-tg1-e1-a1'
                        && ($args['comment'] ?? null) === $marker
                    ) {
                        $createdIpip = true;
                    }

                    return [];
                });
        });

        $result = (new RouterCommandService($mikrotik))->ensure($server, $object);

        $this->assertSame(RouterCommandService::RESULT_CREATED, $result);
        $this->assertTrue($removedGre);
        $this->assertTrue($createdIpip);
    }
}
