<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationLeaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_leave_seller_impersonation(): void
    {
        $admin = User::factory()->admin()->create();
        $seller = User::factory()->seller()->create(['parent_id' => $admin->id]);

        $this->actingAs($admin)
            ->from(route('admin.sellers.index'))
            ->post(route('admin.sellers.impersonate', $seller))
            ->assertRedirect(route('seller.dashboard'));

        $this->assertAuthenticatedAs($seller);

        $response = $this->followingRedirects()
            ->post(route('impersonate.leave'));

        $response->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_agent_can_leave_seller_impersonation(): void
    {
        $agent = User::factory()->agent()->create();
        $seller = User::factory()->seller()->create(['parent_id' => $agent->id]);

        $this->actingAs($agent)
            ->from(route('agent.sellers.index'))
            ->post(route('agent.sellers.impersonate', $seller))
            ->assertRedirect(route('seller.dashboard'));

        $this->assertAuthenticatedAs($seller);

        $response = $this->followingRedirects()
            ->post(route('impersonate.leave'));

        $response->assertOk();
        $this->assertAuthenticatedAs($agent);
    }

    public function test_admin_can_leave_agent_impersonation(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();

        $this->actingAs($admin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.impersonate', $agent))
            ->assertRedirect(route('agent.dashboard'));

        $this->assertAuthenticatedAs($agent);

        $response = $this->followingRedirects()
            ->post(route('impersonate.leave'));

        $response->assertOk();
        $this->assertAuthenticatedAs($admin);
    }
}
