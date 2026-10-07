<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class AccountClientEmailLockTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_an_agent_cannot_change_the_client_email(): void
    {
        $agent = $this->makeAgent();
        [$package] = $this->makePackage();
        $account = $this->makeAccount($agent, $this->makeServer(), ['client_email' => 'label-on-server', 'package_id' => $package->id]);

        $this->actingAs($agent)->put(route('agent.accounts.update', $account), [
            'remote_username' => $account->remote_username,
            'client_email' => 'something-else',
            'status' => $account->status->value,
        ])->assertSessionHasNoErrors();

        $this->assertSame('label-on-server', $account->fresh()->client_email);
        $this->actingAs($agent)->get(route('agent.accounts.edit', $account))
            ->assertOk()
            ->assertDontSee('name="client_email"', false);
    }

    public function test_the_main_admin_can_still_change_the_client_email(): void
    {
        $admin = $this->makeAdmin();
        $account = $this->makeAccount($this->makeAgent(), $this->makeServer(), ['client_email' => 'label-on-server']);

        $this->actingAs($admin)->put(route('admin.accounts.update', $account), [
            'remote_username' => $account->remote_username,
            'client_email' => 'fixed-label',
            'status' => $account->status->value,
        ]);

        $this->assertSame('fixed-label', $account->fresh()->client_email);
    }
}
