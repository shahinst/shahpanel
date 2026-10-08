<?php

namespace Tests\Feature;

use App\Exceptions\ServerUnreachableException;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class MikrotikUnreachableBreakerTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_a_router_that_failed_to_connect_is_not_retried_for_a_minute(): void
    {
        config(['shahpanel.sync_api_retry_attempts' => 2]);
        $server = $this->makeServer('mikrotik');
        $mikrotik = new class extends MikrotikService
        {
            public int $attempts = 0;

            public function __construct() {}

            protected function createClient(\App\Models\Server $server, ?int $connectTimeout = null, ?int $socketTimeout = null, ?int $attempts = null): \RouterOS\Client
            {
                $this->attempts++;

                throw new \RuntimeException('Connection timed out');
            }
        };

        try {
            $mikrotik->connect($server);
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(ServerUnreachableException::class, $e);
        }
        $this->assertSame(2, $mikrotik->attempts);

        // Every later call fails at once, without touching the network.
        $this->expectException(ServerUnreachableException::class);
        try {
            $mikrotik->connect($server);
        } finally {
            $this->assertSame(2, $mikrotik->attempts);
        }
    }

    public function test_the_router_is_tried_again_after_the_pause(): void
    {
        $server = $this->makeServer('mikrotik');
        \Illuminate\Support\Facades\Cache::put('mikrotik:unreachable:'.$server->id, true, now()->addMinute());
        $this->travel(61)->seconds();

        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('mikrotik:unreachable:'.$server->id));
    }
}
