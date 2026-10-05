<?php

namespace Tests\Feature;

use App\Enums\ServiceType;
use App\Models\ServerInterface;
use App\Services\UserPackageAssignmentService;
use App\Support\PanelExtensions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Dedicated\Models\DedicatedServer;
use Modules\Dedicated\Services\DedicatedPackageService;
use Modules\Dedicated\Services\UsageMeter;
use Tests\Concerns\BootsDedicatedModule;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class DedicatedAgentTest extends TestCase
{
    use BootsDedicatedModule;
    use CreatesPanelData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDedicatedModule();
    }

    private function packageData(int $serverId, array $extra = []): array
    {
        return $extra + [
            'name' => 'My WG', 'server_id' => $serverId, 'service_type' => ServiceType::Wireguard->value,
            'targets' => ['profile:wg:wg-own'], 'data_limit_gb' => null, 'is_active' => true,
            'durations' => ['1m' => ['is_enabled' => 1, 'price' => 150000]],
        ];
    }

    public function test_an_agent_builds_packages_only_on_their_own_server(): void
    {
        $agent = $this->makeAgent();
        $own = $this->makeServer('mikrotik');
        $foreign = $this->makeServer('mikrotik');
        ServerInterface::query()->create(['server_id' => $own->id, 'remote_key' => 'profile:wg:wg-own', 'name' => 'wg-own', 'category' => 'wireguard']);
        ServerInterface::query()->create(['server_id' => $foreign->id, 'remote_key' => 'profile:wg:wg-x', 'name' => 'wg-x', 'category' => 'wireguard']);
        DedicatedServer::query()->create(['agent_user_id' => $agent->id, 'server_id' => $own->id]);
        $service = app(DedicatedPackageService::class);

        $package = $service->save($agent, $this->packageData($own->id));
        $this->assertTrue($package->isDedicatedPackage());
        $this->assertSame(['profile:wg:wg-own'], $package->mikrotik_profile_keys);

        // Another server, or another server's profile smuggled in, is refused.
        $this->expectException(InvalidArgumentException::class);
        $service->save($agent, $this->packageData($foreign->id, ['targets' => ['profile:wg:wg-x']]));
    }

    public function test_a_profile_from_another_server_is_dropped(): void
    {
        $agent = $this->makeAgent();
        $own = $this->makeServer('mikrotik');
        $foreign = $this->makeServer('mikrotik');
        ServerInterface::query()->create(['server_id' => $foreign->id, 'remote_key' => 'profile:wg:wg-x', 'name' => 'wg-x', 'category' => 'wireguard']);
        DedicatedServer::query()->create(['agent_user_id' => $agent->id, 'server_id' => $own->id]);

        $this->expectException(InvalidArgumentException::class);
        app(DedicatedPackageService::class)->save($agent, $this->packageData($own->id, ['targets' => ['profile:wg:wg-x']]));
    }

    public function test_the_package_reaches_the_agent_and_their_sellers_only(): void
    {
        $agent = $this->makeAgent();
        $server = $this->makeServer('mikrotik');
        ServerInterface::query()->create(['server_id' => $server->id, 'remote_key' => 'profile:wg:wg-own', 'name' => 'wg-own', 'category' => 'wireguard']);
        DedicatedServer::query()->create(['agent_user_id' => $agent->id, 'server_id' => $server->id]);
        $package = app(DedicatedPackageService::class)->save($agent, $this->packageData($server->id));
        $assign = app(UserPackageAssignmentService::class);

        $this->assertContains($package->id, $assign->agentOwnedPackageIds($agent));
        $this->assertContains($package->id, $assign->agentOwnedPackageIds($this->makeSeller($agent)));
        $this->assertNotContains($package->id, $assign->agentOwnedPackageIds($this->makeAgent()));
    }

    public function test_usage_counts_download_and_survives_a_router_reset(): void
    {
        $row = DedicatedServer::query()->create([
            'agent_user_id' => $this->makeAgent()->id, 'server_id' => $this->makeServer('mikrotik')->id, 'meter_interface' => 'ether1',
        ]);
        $meter = app(UsageMeter::class);

        $this->assertSame(0, $meter->record($row, 5000));      // baseline only
        $this->assertSame(3000, $meter->record($row, 8000));
        $this->assertSame(1200, $meter->record($row, 1200));   // router rebooted
        $this->assertSame(4200, (int) $row->fresh()->total_rx_bytes);
    }

    public function test_only_a_dedicated_agent_opens_the_page_and_sees_only_their_servers(): void
    {
        $agent = $this->makeAgent();
        $mine = $this->makeServer('mikrotik', ['name' => 'srv-mine']);
        $this->makeServer('mikrotik', ['name' => 'srv-theirs']);
        DedicatedServer::query()->create(['agent_user_id' => $agent->id, 'server_id' => $mine->id]);

        $this->actingAs($agent)->get(route('agent.dedicated.index'))
            ->assertOk()->assertSee('srv-mine')->assertDontSee('srv-theirs');
        $this->actingAs($this->makeAgent())->get(route('agent.dedicated.index'))->assertForbidden();
    }

    public function test_the_accounts_menu_shows_only_what_the_own_servers_carry(): void
    {
        $all = ['wireguard', 'ppp', 'v2ray', 'anyconnect'];
        $agent = $this->makeAgent();
        $mine = $this->makeServer('mikrotik');
        DedicatedServer::query()->create(['agent_user_id' => $agent->id, 'server_id' => $mine->id]);

        // A MikroTik carries WireGuard and the PPP family, nothing else.
        $this->assertSame(['wireguard', 'ppp'], PanelExtensions::allowedAccountCategories('agent', $agent, $all));
        $this->assertSame(['wireguard', 'ppp'], PanelExtensions::allowedAccountCategories('seller', $this->makeSeller($agent), $all));

        // Everybody else keeps the whole menu.
        $this->assertSame($all, PanelExtensions::allowedAccountCategories('agent', $this->makeAgent(), $all));
        $this->assertSame($all, PanelExtensions::allowedAccountCategories('admin', $this->makeAdmin(), $all));

        $this->actingAs($agent)->get(route('agent.dedicated.index'))
            ->assertOk()
            ->assertSee(route('agent.accounts.wireguard'), false)
            ->assertDontSee(route('agent.accounts.v2ray'), false);
    }
}
