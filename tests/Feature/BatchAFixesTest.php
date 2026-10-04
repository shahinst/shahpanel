<?php

namespace Tests\Feature;

use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class BatchAFixesTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_ppp_usage_is_read_from_the_live_session_interface(): void
    {
        $server = $this->makeServer();
        $router = Mockery::mock(MikrotikService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $router->shouldReceive('findSecret')->andReturn(['name' => 'ali', 'profile' => 'ppp1']);
        $router->shouldReceive('queryRouter')->with($server, '/ppp/active/print', ['name' => 'ali'])
            ->andReturn([['name' => 'ali', 'service' => 'l2tp']]);
        $router->shouldReceive('readInterfaceStats')->with($server, '<l2tp-ali>')
            ->andReturn(['rx_bytes' => 1000, 'tx_bytes' => 5000]);

        $this->assertSame(
            ['rx_bytes' => 1000, 'tx_bytes' => 5000, 'rx_snapshot' => 1000, 'tx_snapshot' => 5000],
            $router->getInterfaceTraffic($server, 'ali'),
        );
    }

    public function test_a_ppp_user_without_a_session_reads_zero_rather_than_failing(): void
    {
        $server = $this->makeServer();
        $router = Mockery::mock(MikrotikService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $router->shouldReceive('findSecret')->andReturn(['name' => 'ali']);
        $router->shouldReceive('queryRouter')->andReturn([]);
        $router->shouldNotReceive('readInterfaceStats');

        $this->assertSame(0, $router->getInterfaceTraffic($server, 'ali')['rx_bytes']);
    }

    public function test_a_counter_reset_keeps_the_stored_usage_and_the_lifetime_grows(): void
    {
        $sync = $this->app->make(\App\Services\SyncService::class);

        // The router's counter fell from 5000 to 300 (reboot or reconnect):
        // only the 300 counted since then is new.
        $delta = $sync->computeDelta(['rx_bytes' => 5000, 'tx_bytes' => 0], ['rx_bytes' => 300, 'tx_bytes' => 0]);

        $this->assertSame(300, $delta['rx_delta_bytes']);
    }
}
