<?php

namespace Tests\Feature;

use App\Models\InboundAllocation;
use App\Models\Package;
use App\Models\Server;
use App\Models\ServerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Dedicated\Models\DedicatedServer;
use Tests\Concerns\BootsDedicatedModule;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

/**
 * Agents with their own servers build packages on the admin's form, fenced to
 * their own servers and, on an allocation's server, to their own inbounds.
 */
class AgentPackageFormTest extends TestCase
{
    use BootsDedicatedModule;
    use CreatesPanelData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDedicatedModule();
    }

    protected function sanaeiServer(array $inbounds): Server
    {
        $server = $this->makeServer('sanaei');

        foreach ($inbounds as $id) {
            ServerInterface::query()->create([
                'server_id' => $server->id,
                'remote_key' => 'inbound:'.$id,
                'name' => 'in'.$id,
                'category' => 'inbound',
                'protocol' => 'vless',
                'is_enabled' => true,
            ]);
        }

        return $server;
    }

    protected function payload(int $serverId, array $inbounds): array
    {
        return [
            'name' => 'My plan',
            'currency' => 'IRT',
            'service_type' => 'sanaei_vless',
            'pricing_model' => 'fixed',
            'data_limit_gb' => 20,
            'server_ids' => [$serverId],
            'sanaei_inbound_ids' => $inbounds,
            'durations' => ['1m' => ['is_enabled' => 1, 'price' => 150000]],
        ];
    }

    public function test_the_admin_package_pages_wear_the_same_design_and_still_save(): void
    {
        $admin = $this->makeAdmin();
        $server = $this->sanaeiServer([3]);

        $create = $this->actingAs($admin)->get(route('admin.packages.create'))->assertOk()->getContent();
        $this->assertStringContainsString('pkp-hero', $create);
        $this->assertStringContainsString('pkp-save', $create);
        $this->assertStringContainsString('name="kyc_required"', $create);

        $this->actingAs($admin)->post(route('admin.packages.store'), $this->payload($server->id, [3]))
            ->assertRedirect();
        $package = Package::query()->where('name', 'My plan')->firstOrFail();
        $this->assertNull($package->owner_agent_id);

        $edit = $this->actingAs($admin)->get(route('admin.packages.edit', $package))->assertOk()->getContent();
        $this->assertStringContainsString('pkp-hero', $edit);
        $this->assertStringContainsString('My plan', $edit);
    }

    public function test_the_form_is_the_admins_and_shows_only_the_agents_servers(): void
    {
        $agent = $this->makeAgent();
        $mine = $this->sanaeiServer([3]);
        $other = $this->sanaeiServer([4]);
        DedicatedServer::query()->create(['agent_user_id' => $agent->id, 'server_id' => $mine->id]);

        $html = $this->actingAs($agent)->get(route('agent.dedicated.packages.create'))->assertOk()->getContent();

        $this->assertStringContainsString('name="pricing_model"', $html);
        $this->assertStringContainsString('name="server_ids[]" value="'.$mine->id.'"', $html);
        $this->assertStringNotContainsString('name="server_ids[]" value="'.$other->id.'"', $html);
    }

    public function test_a_dedicated_agent_saves_on_own_server_and_is_refused_elsewhere(): void
    {
        $agent = $this->makeAgent();
        $mine = $this->sanaeiServer([3]);
        $other = $this->sanaeiServer([4]);
        DedicatedServer::query()->create(['agent_user_id' => $agent->id, 'server_id' => $mine->id]);

        $this->actingAs($agent)->post(route('agent.dedicated.packages.store'), $this->payload($other->id, [4]))
            ->assertSessionHasErrors('server_ids');
        $this->assertSame(0, Package::query()->count());

        $this->actingAs($agent)->post(route('agent.dedicated.packages.store'), $this->payload($mine->id, [3]))
            ->assertRedirect();

        $package = Package::query()->firstOrFail();
        $this->assertSame($agent->id, (int) $package->owner_agent_id);
        $this->assertNull($package->inbound_allocation_id);
        $this->assertNull($package->package_category_id);
        $this->assertSame(1, $package->durations()->where('is_enabled', true)->count());
    }

    public function test_an_inbound_agent_sells_only_the_inbounds_of_their_allocation(): void
    {
        $agent = $this->makeAgent();
        $server = $this->sanaeiServer([3, 7]);
        $allocation = InboundAllocation::query()->create([
            'agent_user_id' => $agent->id, 'server_id' => $server->id, 'title' => 'A',
            'inbound_ids' => [3], 'quota_bytes' => 10 * 1073741824, 'price_per_gb' => '0.00',
            'currency' => 'IRT', 'credit_limit' => 0, 'status' => InboundAllocation::STATUS_ACTIVE,
        ]);

        $html = $this->actingAs($agent)->get(route('agent.dedicated.packages.create'))->assertOk()->getContent();
        $this->assertStringContainsString('(#3)', $html);
        $this->assertStringNotContainsString('(#7)', $html);

        foreach ([[7], [3, 7], []] as $bad) {
            $this->actingAs($agent)->post(route('agent.dedicated.packages.store'), $this->payload($server->id, $bad))
                ->assertSessionHasErrors();
        }
        $this->assertSame(0, Package::query()->count());

        $this->actingAs($agent)->post(route('agent.dedicated.packages.store'), $this->payload($server->id, [3]))
            ->assertRedirect(route('agent.inbounds.index'));
        $this->assertSame($allocation->id, (int) Package::query()->firstOrFail()->inbound_allocation_id);
    }

    public function test_another_agents_package_cannot_be_edited(): void
    {
        $agent = $this->makeAgent();
        $stranger = $this->makeAgent();
        $server = $this->sanaeiServer([3]);
        DedicatedServer::query()->create(['agent_user_id' => $agent->id, 'server_id' => $server->id]);
        $this->actingAs($agent)->post(route('agent.dedicated.packages.store'), $this->payload($server->id, [3]));
        $package = Package::query()->firstOrFail();

        $other = $this->sanaeiServer([4]);
        DedicatedServer::query()->create(['agent_user_id' => $stranger->id, 'server_id' => $other->id]);

        $this->actingAs($stranger)->get(route('agent.dedicated.packages.edit', $package))->assertNotFound();
        $this->actingAs($agent)->get(route('agent.dedicated.packages.edit', $package))->assertOk();
        $this->actingAs($this->makeAgent())->get(route('agent.dedicated.packages.create'))->assertForbidden();
    }

    public function test_identity_checks_are_neither_offered_nor_accepted_on_an_agents_package(): void
    {
        $agent = $this->makeAgent();
        $server = $this->sanaeiServer([3]);
        DedicatedServer::query()->create(['agent_user_id' => $agent->id, 'server_id' => $server->id]);

        $this->actingAs($agent)->get(route('agent.dedicated.packages.create'))
            ->assertOk()
            ->assertDontSee('name="kyc_required"', false);

        $this->actingAs($agent)->post(route('agent.dedicated.packages.store'), $this->payload($server->id, [3]) + ['kyc_required' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) Package::query()->where('owner_agent_id', $agent->id)->latest('id')->value('kyc_required'));
    }
}
