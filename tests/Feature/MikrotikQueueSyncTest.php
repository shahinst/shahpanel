<?php

namespace Tests\Feature;

use App\Models\ServerInterface;
use App\Services\MikrotikInterfaceRemovalService;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class MikrotikQueueSyncTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_queues_are_reconciled_in_one_read_and_only_changes_are_written(): void
    {
        $server = $this->makeServer();
        $writes = [];

        $router = Mockery::mock(MikrotikService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $router->shouldReceive('queryRouter')->once()->andReturn([
            // RouterOS prints limits in bits: this one already matches "500M/500M".
            ['.id' => '*1', 'name' => 'wg1', 'target' => 'wg1', 'max-limit' => '500000000/500000000', 'parent' => 'none', 'disabled' => 'false'],
            ['.id' => '*2', 'name' => 'wg1-2-10', 'target' => '10.0.0.2/32', 'max-limit' => '10000000/10000000', 'parent' => 'wg1', 'disabled' => 'false'],
            // Left over from an earlier speed.
            ['.id' => '*3', 'name' => 'wg1-3-5', 'target' => '10.0.0.3/32', 'max-limit' => '5000000/5000000', 'parent' => 'wg1', 'disabled' => 'false'],
            ['.id' => '*4', 'name' => 'wg1-4-10', 'target' => '10.0.0.4/32', 'max-limit' => '10000000/10000000', 'parent' => 'wg1', 'disabled' => 'true'],
            // A customer's own ceiling: not the interface's to touch.
            ['.id' => '*5', 'name' => 'wg1-acct-77', 'target' => '10.0.0.9/32', 'max-limit' => '2000000/8000000', 'parent' => 'none', 'disabled' => 'false'],
            ['.id' => '*6', 'name' => 'wg12-2-10', 'target' => '10.1.0.2/32', 'max-limit' => '1/1', 'parent' => 'wg12', 'disabled' => 'false'],
        ]);
        $router->shouldReceive('execute')->andReturnUsing(function ($s, string $path, array $attrs) use (&$writes) {
            $writes[] = $path.' '.($attrs['name'] ?? $attrs['.id']);

            return [];
        });

        $result = $router->syncInterfaceQueues($server, 'wg1', [
            ['name' => 'wg1-2-10', 'target' => '10.0.0.2', 'max-limit' => '10M/10M', 'parent' => 'wg1'],
            ['name' => 'wg1-3-10', 'target' => '10.0.0.3', 'max-limit' => '10M/10M', 'parent' => 'wg1'],
            ['name' => 'wg1-4-10', 'target' => '10.0.0.4', 'max-limit' => '10M/10M', 'parent' => 'wg1'],
            ['name' => 'wg1', 'target' => 'wg1', 'max-limit' => '500M/500M'],
        ]);

        $this->assertSame(['added' => 0, 'updated' => 2, 'removed' => 0, 'unchanged' => 2, 'errors' => []], $result);
        $this->assertSame([
            '/queue/simple/set wg1-3-10',   // the old 5M queue on that address, rewritten in place
            '/queue/simple/set wg1-4-10',   // was disabled
        ], $writes);
    }

    public function test_a_speed_change_rewrites_each_queue_in_place(): void
    {
        $server = $this->makeServer();
        $writes = [];

        $router = Mockery::mock(MikrotikService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $router->shouldReceive('queryRouter')->once()->andReturn([
            ['.id' => '*1', 'name' => 'wg1', 'target' => 'wg1', 'max-limit' => '500000000/500000000', 'parent' => 'none', 'disabled' => 'false'],
            ['.id' => '*2', 'name' => 'wg1-2-10', 'target' => '10.0.0.2/32', 'max-limit' => '10000000/10000000', 'parent' => 'wg1', 'disabled' => 'false'],
            ['.id' => '*3', 'name' => 'wg1-3-10', 'target' => '10.0.0.3/32', 'max-limit' => '10000000/10000000', 'parent' => 'wg1', 'disabled' => 'false'],
        ]);
        $router->shouldReceive('execute')->andReturnUsing(function ($s, string $path, array $attrs) use (&$writes) {
            $writes[] = $path.' '.($attrs['.id'] ?? '').' '.($attrs['name'] ?? '');

            return [];
        });

        $result = $router->syncInterfaceQueues($server, 'wg1', [
            ['name' => 'wg1', 'target' => 'wg1', 'max-limit' => '500M/500M'],
            ['name' => 'wg1-2-20', 'target' => '10.0.0.2', 'max-limit' => '20M/20M', 'parent' => 'wg1'],
            ['name' => 'wg1-3-20', 'target' => '10.0.0.3', 'max-limit' => '20M/20M', 'parent' => 'wg1'],
        ]);

        // Nothing added, nothing removed: both queues were renamed and re-limited where they stand.
        $this->assertSame(['added' => 0, 'updated' => 2, 'removed' => 0, 'unchanged' => 1, 'errors' => []], $result);
        $this->assertSame(['/queue/simple/set *2 wg1-2-20', '/queue/simple/set *3 wg1-3-20'], $writes);
    }

    public function test_a_failed_queue_is_reported_and_the_rest_still_written(): void
    {
        $server = $this->makeServer();
        $router = Mockery::mock(MikrotikService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $router->shouldReceive('queryRouter')->andReturn([]);
        $router->shouldReceive('execute')->andReturnUsing(function ($s, string $path, array $attrs) {
            if (($attrs['name'] ?? '') === 'wg1-2-10') {
                throw new RuntimeException('failure: already have such queue');
            }

            return [];
        });

        $result = $router->syncInterfaceQueues($server, 'wg1', [
            ['name' => 'wg1', 'target' => 'wg1', 'max-limit' => '500M/500M'],
            ['name' => 'wg1-2-10', 'target' => '10.0.0.2', 'max-limit' => '10M/10M', 'parent' => 'wg1'],
            ['name' => 'wg1-3-10', 'target' => '10.0.0.3', 'max-limit' => '10M/10M', 'parent' => 'wg1'],
        ]);

        $this->assertSame(2, $result['added']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('wg1-2-10', $result['errors'][0]);
    }

    public function test_a_profile_still_used_by_an_account_is_not_removed(): void
    {
        $server = $this->makeServer();
        $iface = ServerInterface::query()->create([
            'server_id' => $server->id, 'category' => 'ppp', 'name' => 'ppp-plan9',
            'remote_key' => 'ppp:ppp-plan9', 'is_enabled' => true, 'meta' => [],
        ]);
        $this->makeAccount($this->makeAgent(), $server, ['mikrotik_profile_key' => 'ppp:ppp-plan9']);

        $router = Mockery::mock(MikrotikService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $router->shouldNotReceive('execute');
        $this->app->instance(MikrotikService::class, $router);

        try {
            $this->app->make(MikrotikInterfaceRemovalService::class)->removePpp($server, $iface);
            $this->fail('removal should have been refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ppp-plan9', $e->getMessage());
        }

        $this->assertNotNull($iface->fresh());

        // RouterOS's own profiles are refused before anything else is asked.
        $builtin = ServerInterface::query()->create([
            'server_id' => $server->id, 'category' => 'ppp', 'name' => 'default',
            'remote_key' => 'ppp:default', 'is_enabled' => true, 'meta' => [],
        ]);
        $this->expectException(RuntimeException::class);
        $this->app->make(MikrotikInterfaceRemovalService::class)->removePpp($server, $builtin);
    }
}
