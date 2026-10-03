<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class ExpiringAccountsTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_each_role_sees_only_its_own_scope_and_filters_work(): void
    {
        $admin = $this->makeAdmin();
        $agentA = $this->makeAgent();
        $agentB = $this->makeAgent();
        $sellerA = $this->makeSeller($agentA);
        $sellerB = $this->makeSeller($agentB);
        $server = $this->makeServer();

        $a1 = $this->makeAccount($sellerA, $server, ['remote_username' => 'aaa_soon', 'expiry_at' => now()->addHours(10)]);
        $a2 = $this->makeAccount($sellerA, $server, ['remote_username' => 'aaa_later', 'expiry_at' => now()->addDays(6), 'service_type' => ServiceType::Ppp]);
        $own = $this->makeAccount($agentA, $server, ['remote_username' => 'agent_own', 'expiry_at' => now()->addDays(2)]);
        $b1 = $this->makeAccount($sellerB, $server, ['remote_username' => 'bbb_soon', 'expiry_at' => now()->addDays(1)]);
        $this->makeAccount($sellerA, $server, ['remote_username' => 'far_away', 'expiry_at' => now()->addDays(20)]);
        $this->makeAccount($sellerA, $server, ['remote_username' => 'disabled_one', 'expiry_at' => now()->addDay(), 'status' => AccountStatus::Disabled]);

        $this->actingAs($admin)->get(route('admin.accounts.expiring', ['days' => 7]))
            ->assertOk()->assertSee('aaa_soon')->assertSee('aaa_later')->assertSee('bbb_soon')->assertSee('agent_own')
            ->assertDontSee('far_away')->assertDontSee('disabled_one');

        $this->actingAs($admin)->get(route('admin.accounts.expiring', ['days' => 7, 'agent_id' => $agentB->id]))
            ->assertOk()->assertSee('bbb_soon')->assertDontSee('aaa_soon');

        $this->actingAs($admin)->get(route('admin.accounts.expiring', ['days' => 1]))
            ->assertOk()->assertSee('aaa_soon')->assertDontSee('aaa_later');

        $this->actingAs($admin)->get(route('admin.accounts.expiring', ['days' => 7, 'category' => 'ppp']))
            ->assertOk()->assertSee('aaa_later')->assertDontSee('aaa_soon');

        $this->actingAs($agentA)->get(route('agent.accounts.expiring', ['days' => 7]))
            ->assertOk()->assertSee('aaa_soon')->assertSee('agent_own')->assertDontSee('bbb_soon');

        $this->actingAs($agentA)->get(route('agent.accounts.expiring', ['days' => 7, 'seller_id' => $agentA->id]))
            ->assertOk()->assertSee('agent_own')->assertDontSee('aaa_soon');

        $this->actingAs($sellerB)->get(route('seller.accounts.expiring', ['days' => 7]))
            ->assertOk()->assertSee('bbb_soon')->assertDontSee('aaa_soon');

        $csv = $this->actingAs($agentA)->get(route('agent.accounts.expiring.export', ['days' => 7]))->streamedContent();
        $this->assertStringContainsString('aaa_soon', $csv);
        $this->assertStringNotContainsString('bbb_soon', $csv);

        // The menu entry is there for every staff role.
        $this->actingAs($sellerA)->get(route('seller.dashboard'))->assertSee(route('seller.accounts.expiring'), false);
    }
}
