<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\InboundAllocation;
use App\Models\PackageDuration;
use App\Models\ServerInterface;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserPackageDurationPrice;
use App\Services\UserPackagePricingService;
use App\Services\WalletService;
use App\Support\PanelExtensions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Dedicated\Models\DedicatedServer;
use Modules\Dedicated\Models\InboundChargeRequest;
use Modules\Dedicated\Models\InboundVolumePack;
use Modules\Dedicated\Services\InboundVolumeService;
use Tests\Concerns\BootsDedicatedModule;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class InboundVolumeTest extends TestCase
{
    use BootsDedicatedModule;
    use CreatesPanelData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDedicatedModule();
    }

    private function allocation($agent, $server): InboundAllocation
    {
        return InboundAllocation::query()->create([
            'agent_user_id' => $agent->id, 'server_id' => $server->id, 'inbound_ids' => [3],
            'quota_bytes' => 10 * InboundAllocation::GB, 'price_per_gb' => '0.00', 'currency' => 'IRT', 'credit_limit' => 0,
        ]);
    }

    private function pack($server, int $gb = 100, string $price = '500000.00'): InboundVolumePack
    {
        return InboundVolumePack::query()->create(['server_id' => $server->id, 'title' => 'P', 'gb' => $gb, 'price' => $price, 'currency' => 'IRT', 'is_active' => true]);
    }

    public function test_approval_gives_the_volume_and_moves_the_money_to_the_admin(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $server = $this->makeServer('sanaei');
        $allocation = $this->allocation($agent, $server);
        $wallets = app(WalletService::class);
        $wallets->credit($agent, '1000000.00', TransactionType::Charge);
        // Revenue goes to the panel owner (the first admin), not to whoever
        // reviewed the request -- the same rule usage billing uses.
        $owner = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();

        $service = app(InboundVolumeService::class);
        $request = $service->request($agent, $allocation, $this->pack($server));

        // The admin gives half of what was asked: the price follows the volume given.
        $service->approve($request, 50, $admin);

        $this->assertSame(60 * InboundAllocation::GB, (int) $allocation->fresh()->quota_bytes);
        $this->assertSame('750000.00', (string) $wallets->getOrCreateWallet($agent)->fresh()->balance);
        // An admin wallet is infinite and never moves; the sale is booked as a
        // revenue transaction, which is what the admin's accounting reads.
        $this->assertTrue(Transaction::query()
            ->where('user_id', $owner->id)
            ->where('type', TransactionType::Revenue)
            ->where('amount', '250000.00')
            ->exists());
        $this->assertSame(InboundChargeRequest::APPROVED, $request->fresh()->status);
        $this->assertSame('250000.00', (string) $request->fresh()->charged_amount);
    }

    public function test_a_request_is_settled_once_and_never_without_the_money(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $server = $this->makeServer('sanaei');
        $allocation = $this->allocation($agent, $server);
        $service = app(InboundVolumeService::class);

        // No balance: nothing is given and nothing is booked.
        $poor = $service->request($agent, $allocation, $this->pack($server));
        try {
            $service->approve($poor, 100, $admin);
            $this->fail('approved without balance');
        } catch (\Throwable) {
        }
        $this->assertSame(10 * InboundAllocation::GB, (int) $allocation->fresh()->quota_bytes);
        $this->assertSame(InboundChargeRequest::PENDING, $poor->fresh()->status);

        app(WalletService::class)->credit($agent, '500000.00', TransactionType::Charge);
        $service->approve($poor, 100, $admin);

        // A second click on the same request does not charge again.
        $this->expectException(InvalidArgumentException::class);
        $service->approve($poor, 100, $admin);
    }

    public function test_an_agent_cannot_charge_someone_elses_inbound_or_use_a_foreign_pack(): void
    {
        $agent = $this->makeAgent();
        $other = $this->makeAgent();
        $server = $this->makeServer('sanaei');
        $elsewhere = $this->makeServer('sanaei');
        $service = app(InboundVolumeService::class);

        try {
            $service->request($agent, $this->allocation($other, $server), $this->pack($server));
            $this->fail('charged a foreign inbound');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $service->request($agent, $this->allocation($agent, $server), $this->pack($elsewhere));
    }

    public function test_an_agent_sets_each_sellers_price_on_their_own_packages(): void
    {
        $agent = $this->makeAgent();
        $seller = $this->makeSeller($agent);
        $stranger = $this->makeSeller($this->makeAgent());
        [$package] = $this->makePackage();
        $package->update(['owner_agent_id' => $agent->id]);
        $duration = PackageDuration::query()->where('package_id', $package->id)->first();
        $duration->update(['price' => '200000.00']);
        $pricing = app(UserPackagePricingService::class);

        $this->assertSame('200000.00', (string) $pricing->wholesalePriceFor($seller, $duration->fresh()));

        UserPackageDurationPrice::query()->create(['user_id' => $seller->id, 'package_duration_id' => $duration->id, 'wholesale_price' => '170000.00']);
        $this->assertSame('170000.00', (string) $pricing->wholesalePriceFor($seller, $duration->fresh()));
        $this->assertSame('0.00', (string) $pricing->wholesalePriceFor($agent, $duration->fresh()));
        $this->assertNull($pricing->wholesalePriceFor($stranger, $duration->fresh()));
    }

    public function test_special_agents_stay_off_the_regular_agents_list(): void
    {
        $admin = $this->makeAdmin();
        $regular = $this->makeAgent();
        $inbound = $this->makeAgent();
        $dedicated = $this->makeAgent();
        $this->allocation($inbound, $this->makeServer('sanaei'));
        DedicatedServer::query()->create(['agent_user_id' => $dedicated->id, 'server_id' => $this->makeServer('mikrotik')->id]);

        $excluded = PanelExtensions::excludedAgentIds();
        $this->assertContains($inbound->id, $excluded);
        $this->assertContains($dedicated->id, $excluded);
        $this->assertNotContains($regular->id, $excluded);

        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertOk()->assertSee($regular->username)->assertDontSee($inbound->username)->assertDontSee($dedicated->username);
        $this->actingAs($admin)->get(route('admin.inbound-agents.index'))->assertOk()->assertSee($inbound->username);
    }

    public function test_an_inbound_agent_is_made_on_its_own_page_and_set_up_on_theirs(): void
    {
        $admin = $this->makeAdmin();
        $server = $this->makeServer('sanaei');
        ServerInterface::query()->create(['server_id' => $server->id, 'remote_key' => 'inbound:3', 'name' => 'vless-3', 'category' => 'inbound']);

        $this->actingAs($admin)->get(route('admin.inbound-agents.create'))->assertOk()->assertSee('vless-3');

        $response = $this->actingAs($admin)->post(route('admin.inbound-agents.store'), [
            'full_name' => 'Inbound One', 'username' => 'inbound-one', 'email' => 'in1@example.test',
            'password' => 'secret-pass', 'server_id' => $server->id, 'inbound_ids' => [3], 'quota_gb' => 20,
        ]);

        $agent = User::query()->where('username', 'inbound-one')->firstOrFail();
        $allocation = InboundAllocation::query()->where('agent_user_id', $agent->id)->firstOrFail();
        $response->assertRedirect(route('admin.inbound-agents.edit', $agent));
        $this->assertSame(20 * InboundAllocation::GB, (int) $allocation->quota_bytes);
        $this->assertSame([3], $allocation->inboundIdList());

        // The list is a table of agents; the settings live on the agent's own page.
        $this->actingAs($admin)->get(route('admin.inbound-agents.index'))->assertOk()
            ->assertSee('inbound-one')->assertSee(route('admin.inbound-agents.edit', $agent));
        $this->actingAs($admin)->get(route('admin.inbound-agents.edit', $agent))->assertOk()->assertSee('vless-3');
        $this->actingAs($admin)->get(route('admin.inbound-agents.volume'))->assertOk();

        $this->actingAs($admin)->put(route('admin.inbound-agents.inbounds.update', ['allocation' => $allocation]), [
            'title' => 'Main', 'inbound_ids' => [3], 'quota_gb' => 50,
        ])->assertSessionHasNoErrors();
        $this->assertSame(50 * InboundAllocation::GB, (int) $allocation->fresh()->quota_bytes);

        // A regular agent has no inbound settings page.
        $this->actingAs($admin)->get(route('admin.inbound-agents.edit', $this->makeAgent()))->assertNotFound();
    }

    public function test_a_dedicated_agent_is_made_with_a_server_and_keeps_at_least_one(): void
    {
        $admin = $this->makeAdmin();
        $server = $this->makeServer('mikrotik');

        $this->actingAs($admin)->post(route('admin.dedicated.store'), [
            'full_name' => 'Own Server', 'username' => 'own-server', 'email' => 'own@example.test',
            'password' => 'secret-pass', 'server_id' => $server->id, 'meter_interface' => 'ether1',
        ])->assertSessionHasNoErrors();

        $agent = User::query()->where('username', 'own-server')->firstOrFail();
        $row = DedicatedServer::query()->where('agent_user_id', $agent->id)->firstOrFail();
        $this->assertSame($server->id, (int) $row->server_id);

        $this->actingAs($admin)->get(route('admin.dedicated.index'))->assertOk()
            ->assertSee('own-server')->assertSee(route('admin.dedicated.edit', $agent));
        $this->actingAs($admin)->get(route('admin.dedicated.edit', $agent))->assertOk()->assertSee($server->name);

        // Taking away the only server would leave an agent with nothing to run.
        $this->actingAs($admin)->delete(route('admin.dedicated.destroy', $row));
        $this->assertTrue(DedicatedServer::query()->whereKey($row->id)->exists());
    }
}
