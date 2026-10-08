<?php

namespace Tests\Feature;

use App\Services\MikrotikPppProfileService;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class PppProfilePortAndIpsecTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    /** @var list<array{0: string, 1: array}> */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(MikrotikService::class, \Mockery::mock(MikrotikService::class, function ($mock) {
            $mock->shouldReceive('pppProfileExists')->andReturn(false);
            $mock->shouldReceive('createPppProfileWithPool')->andReturn(['gateway' => '10.30.0.1', 'ranges' => '10.30.0.2-10.30.0.254']);
            $mock->shouldReceive('detectEnabledServicePorts')->andReturn(['l2tp' => 1701, 'sstp' => 443]);
            $mock->shouldReceive('sendCommand')->andReturnUsing(function ($server, string $path, array $attributes = []) {
                $this->commands[] = [$path, $attributes];

                return [];
            });
            $mock->shouldIgnoreMissing();
        }));
    }

    public function test_an_encrypted_l2tp_profile_uses_the_server_ipsec_secret(): void
    {
        $server = $this->makeServer('mikrotik');
        $profiles = app(MikrotikPppProfileService::class);

        // No secret stored yet: refused instead of saving a profile nobody can use.
        try {
            $profiles->create($server, 'l2tpsec', '10.30.0.0/24', 'l2tp', true);
            $this->fail('An encrypted L2TP profile was created without an IPsec secret.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(__('servers.ppp_ipsec_secret_missing'), $e->getMessage());
        }

        $server->update(['l2tp_ipsec_secret_enc' => 'S3cret-psk']);
        $profile = $profiles->create($server->fresh(), 'l2tpsec', '10.30.0.0/24', 'l2tp', true);

        $this->assertTrue($profile->meta['ipsec']);
        $this->assertContains(['/interface/l2tp-server/server/set', ['enabled' => 'yes', 'use-ipsec' => 'yes', 'ipsec-secret' => 'S3cret-psk']], $this->commands);
    }

    public function test_the_port_is_set_where_the_protocol_allows_it(): void
    {
        $server = $this->makeServer('mikrotik');
        $profiles = app(MikrotikPppProfileService::class);

        $profile = $profiles->create($server, 'sstp1', '10.31.0.0/24', 'sstp', false, null, null, 8443);
        $this->assertSame(8443, (int) $profile->port);
        $this->assertContains(['/interface/sstp-server/server/set', ['port' => '8443']], $this->commands);

        $this->expectExceptionMessage(__('servers.ppp_port_fixed', ['protocol' => 'L2TP', 'port' => 1701]));
        $profiles->create($server, 'l2tp2', '10.32.0.0/24', 'l2tp', false, null, null, 1702);
    }
}
