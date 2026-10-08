<?php

namespace Tests\Feature;

use App\Models\BlockedIp;
use App\Services\IpGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class LoginGuardPolicyTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_the_admin_sets_how_many_wrong_passwords_block_an_ip(): void
    {
        $this->actingAs($this->makeAdmin())
            ->post(route('admin.login-firewall.policy'), [
                'login_guard_max_attempts' => 5,
                'login_guard_window_minutes' => 30,
                'login_guard_block_minutes' => 120,
                'login_guard_escalate_after' => 40,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([5, 30, 120, 40], [IpGuardService::maxAttempts(), IpGuardService::windowMinutes(), IpGuardService::blockMinutes(), IpGuardService::escalateAfter()]);

        $guard = app(IpGuardService::class);
        $ip = '203.0.113.7';

        for ($i = 1; $i <= 4; $i++) {
            $this->assertNull($guard->recordFailure($ip, 'admin', 'ua', 'login'), "blocked after {$i}");
        }

        $block = $guard->recordFailure($ip, 'admin', 'ua', 'login');
        $this->assertInstanceOf(BlockedIp::class, $block);
        $this->assertEqualsWithDelta(120, now()->diffInMinutes($block->expires_at), 1);
    }

    public function test_values_outside_the_safe_range_are_refused_and_defaults_apply(): void
    {
        $this->assertSame(IpGuardService::MAX_ATTEMPTS, IpGuardService::maxAttempts());

        // One typo must never lock the admin out.
        $this->actingAs($this->makeAdmin())
            ->post(route('admin.login-firewall.policy'), [
                'login_guard_max_attempts' => 1,
                'login_guard_window_minutes' => 15,
                'login_guard_block_minutes' => 0,
                'login_guard_escalate_after' => 20,
            ])
            ->assertSessionHasErrors(['login_guard_max_attempts', 'login_guard_block_minutes']);

        $this->assertSame(IpGuardService::MAX_ATTEMPTS, IpGuardService::maxAttempts());
        $this->get(route('admin.login-firewall.index'))->assertOk()->assertSee('name="login_guard_max_attempts"', false);
    }
}
