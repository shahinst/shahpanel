<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Setting;
use App\Services\ImpersonationService;
use App\Support\PortalPaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ImpersonationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_leave_returns_normalized_legacy_admin_path(): void
    {
        config(['app.url' => 'http://localhost']);
        Setting::query()->updateOrCreate(
            ['key' => 'portal_path_admin'],
            ['value' => 'cp-admin'],
        );
        PortalPaths::clearCache();

        $this->assertSame('cp-admin', PortalPaths::slug('admin'));

        $admin = User::factory()->admin()->create();
        $seller = User::factory()->seller()->create(['parent_id' => $admin->id]);

        $this->actingAs($admin);

        app(ImpersonationService::class)->start($admin, $seller);

        session([
            ImpersonationService::SESSION_RETURN_URL => 'http://localhost/admin/sellers',
        ]);

        $result = app(ImpersonationService::class)->leave();

        $this->assertSame($admin->id, $result['user']->id);
        $this->assertSame('http://localhost/cp-admin/sellers', $result['returnUrl']);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_leave_falls_back_to_actor_dashboard_for_foreign_host(): void
    {
        $agent = User::factory()->agent()->create();
        $seller = User::factory()->seller()->create(['parent_id' => $agent->id]);

        $this->actingAs($agent);

        app(ImpersonationService::class)->start($agent, $seller);

        session([
            ImpersonationService::SESSION_RETURN_URL => 'https://evil.example/agent/sellers',
        ]);

        $result = app(ImpersonationService::class)->leave();

        $this->assertSame(route('agent.sellers.index'), $result['returnUrl']);
    }

    public function test_start_prefers_safe_previous_url(): void
    {
        $agent = User::factory()->agent()->create();
        $seller = User::factory()->seller()->create(['parent_id' => $agent->id]);

        $this->actingAs($agent)
            ->from(route('agent.sellers.index'))
            ->post(route('agent.sellers.impersonate', $seller));

        $this->assertSame(
            route('agent.sellers.index'),
            session(ImpersonationService::SESSION_RETURN_URL),
        );
    }
}
