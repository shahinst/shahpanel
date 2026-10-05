<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountUsageLog;
use App\Models\InboundAllocation;
use App\Models\Package;
use App\Models\ServerInterface;
use App\Models\User;
use App\Services\AccountService;
use App\Services\InboundReseller\AgentPackageService;
use App\Services\InboundReseller\InboundAllocationService;
use App\Services\SanaeiService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\BootsDedicatedModule;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class InboundResellerTest extends TestCase
{
    use BootsDedicatedModule;
    use CreatesPanelData;
    use RefreshDatabase;

    protected User $admin;

    protected User $agent;

    protected User $seller;

    protected InboundAllocation $allocation;

    protected Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDedicatedModule();
        Queue::fake();

        $this->admin = $this->makeAdmin();
        $this->agent = $this->makeAgent();
        $this->seller = $this->makeSeller($this->agent);
        $server = $this->makeServer('sanaei');

        ServerInterface::query()->forceCreate([
            'server_id' => $server->id, 'remote_key' => 'inbound:7', 'name' => 'vless-main',
            'category' => 'inbound', 'protocol' => 'vless', 'port' => 443, 'is_enabled' => true,
        ]);

        $this->actingAs($this->admin)->post(route('admin.inbound-allocations.store'), [
            'agent_user_id' => $this->agent->id,
            'server_id' => $server->id,
            'inbound_ids' => [7],
            'quota_amount' => 10,
            'quota_unit' => 'gb',
            'price_per_gb' => 1000,
            'currency' => 'IRT',
            'credit_limit' => 5000,
        ])->assertRedirect(route('admin.inbound-allocations.index'));

        $this->allocation = InboundAllocation::query()->firstOrFail();

        $this->package = app(AgentPackageService::class)->save($this->agent, $this->allocation, [
            'name' => 'My 30 GB',
            'data_limit_gb' => 30,
            'sanaei_limit_ip' => 2,
            'is_active' => true,
            'durations' => ['1m' => ['is_enabled' => 1, 'price' => 50000]],
        ]);

        $sanaei = Mockery::mock(SanaeiService::class)->makePartial();
        $sanaei->shouldReceive('resolveProvisionInboundIds')->andReturn([7]);
        $sanaei->shouldReceive('createClient')->andReturnUsing(fn () => ['subId' => 'abc']);
        $sanaei->shouldReceive('disableAccountClients', 'enableAccountClients', 'updateAccountClients')->andReturnNull();
        $this->app->instance(SanaeiService::class, $sanaei);
    }

    protected function buy(User $buyer): Account
    {
        $duration = $this->package->durations()->where('tier', '1m')->firstOrFail();

        return $this->app->make(AccountService::class)->createAccount(
            $buyer, $this->package->fresh(), $this->allocation->server, $duration,
            ['skip_portal_client' => true, 'auto_random_remote_identity' => true],
        );
    }

    protected function balance(User $user): string
    {
        return number_format((float) app(WalletService::class)->getOrCreateWallet($user)->fresh()->balance, 2, '.', '');
    }

    public function test_agent_package_is_visible_only_to_the_agent_and_its_sellers(): void
    {
        $this->assertSame([7], $this->package->sanaei_inbound_ids);
        $this->assertSame(2, $this->package->sanaei_limit_ip);
        $this->assertTrue($this->package->isAgentOwned());

        $assign = app(\App\Services\UserPackageAssignmentService::class);
        $this->assertTrue($assign->userHasPackage($this->agent, $this->package->id));
        $this->assertTrue($assign->userHasPackage($this->seller, $this->package->id));
        $this->assertFalse($assign->userHasPackage($this->makeSeller($this->makeAgent()), $this->package->id));
        $this->assertFalse($assign->userHasPackage($this->admin, $this->package->id));
    }

    public function test_sales_pay_the_agent_and_traffic_is_billed_per_gb(): void
    {
        app(WalletService::class)->credit($this->seller, '100000.00', TransactionType::Charge);

        $own = $this->buy($this->agent);
        $sold = $this->buy($this->seller);

        $this->assertSame($this->allocation->id, $own->inbound_allocation_id);
        $this->assertSame('50000.00', $this->balance($this->seller));
        $this->assertSame('50000.00', $this->balance($this->agent));

        // 3.5 GB used across the two accounts.
        AccountUsageLog::query()->create(['account_id' => $own->id, 'rx_delta_bytes' => 2 * InboundAllocation::GB, 'tx_delta_bytes' => 0, 'rx_snapshot' => 0, 'tx_snapshot' => 0, 'recorded_at' => now()]);
        AccountUsageLog::query()->create(['account_id' => $sold->id, 'rx_delta_bytes' => (int) (1.5 * InboundAllocation::GB), 'tx_delta_bytes' => 0, 'rx_snapshot' => 0, 'tx_snapshot' => 0, 'recorded_at' => now()]);

        $charged = app(InboundAllocationService::class)->bill($this->allocation);

        $this->assertSame('3000.00', $charged); // 3 whole GB, half a GB carried over
        $this->assertSame('47000.00', $this->balance($this->agent));
        $fresh = $this->allocation->fresh();
        $this->assertSame((int) (3.5 * InboundAllocation::GB), $fresh->used_bytes);
        $this->assertSame(3 * InboundAllocation::GB, $fresh->billed_bytes);

        // Running again without new usage charges nothing.
        $this->assertSame('0.00', app(InboundAllocationService::class)->bill($fresh));
    }

    public function test_quota_suspends_and_resume_restores(): void
    {
        $own = $this->buy($this->agent);
        AccountUsageLog::query()->create(['account_id' => $own->id, 'rx_delta_bytes' => 11 * InboundAllocation::GB, 'tx_delta_bytes' => 0, 'rx_snapshot' => 0, 'tx_snapshot' => 0, 'recorded_at' => now()]);

        app(InboundAllocationService::class)->bill($this->allocation);

        $allocation = $this->allocation->fresh();
        $this->assertSame(InboundAllocation::STATUS_SUSPENDED, $allocation->status);
        $this->assertSame(AccountStatus::Disabled, $own->fresh()->status);

        try {
            $this->buy($this->agent);
            $this->fail('A suspended allocation must not take new accounts.');
        } catch (\InvalidArgumentException) {
        }

        $allocation->update(['quota_bytes' => 100 * InboundAllocation::GB]);
        $this->assertSame(1, app(InboundAllocationService::class)->resume($allocation->fresh()));
        $this->assertSame(AccountStatus::Active, $own->fresh()->status);
    }

    public function test_agent_pages_render_and_are_private(): void
    {
        $this->actingAs($this->agent)->get(route('agent.inbounds.index'))->assertOk()->assertSee('My 30 GB');
        $this->actingAs($this->agent)->get(route('agent.inbounds.packages.edit', $this->package))->assertOk();
        $this->actingAs($this->makeAgent())->get(route('agent.inbounds.packages.edit', $this->package))->assertNotFound();
        $this->actingAs($this->admin)->get(route('admin.inbound-allocations.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.inbound-allocations.edit', $this->allocation))->assertOk();
    }
}
