<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Services\LoginCaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_restricted_admin_cannot_use_the_tunneling_api(): void
    {
        if (! Route::has('admin.tunneling.api.tunnels.index')) {
            $this->markTestSkipped('Tunneling module is not loaded.');
        }

        $this->makeAdmin();
        $restricted = $this->makeAdmin(['admin_section_permissions' => ['dashboard']]);

        $this->actingAs($restricted)
            ->getJson(route('admin.tunneling.api.tunnels.index'))
            ->assertForbidden();
    }

    public function test_impersonation_route_must_match_the_target_role(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();

        $this->actingAs($admin)
            ->post(route('admin.clients.impersonate', $agent))
            ->assertNotFound();

        $this->assertSame($admin->id, auth()->id());
    }

    public function test_only_the_owner_can_remove_another_admins_second_factor(): void
    {
        $owner = $this->makeAdmin();
        $other = $this->makeAdmin();
        $agent = $this->makeAgent();

        $this->assertFalse(Gate::forUser($other)->allows('disableTwoFactor', $owner));
        $this->assertTrue(Gate::forUser($owner)->allows('disableTwoFactor', $other));
        $this->assertTrue(Gate::forUser($other)->allows('disableTwoFactor', $agent));
    }

    public function test_suspended_user_is_logged_out_on_the_next_request(): void
    {
        $agent = $this->makeAgent(['status' => UserStatus::Suspended]);

        $this->actingAs($agent)
            ->get(route('agent.dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_safe_link_drops_script_and_foreign_schemes(): void
    {
        $this->assertSame('https://example.com/a', safe_link('https://example.com/a'));
        $this->assertSame('/agent/accounts', safe_link('/agent/accounts'));
        $this->assertNull(safe_link('javascript:alert(1)'));
        $this->assertNull(safe_link('data:text/html,x'));
        $this->assertNull(safe_link('//evil.example'));
        $this->assertNull(safe_link('/\\evil.example'));
        $this->assertNull(safe_link(''));
    }

    public function test_captcha_svg_has_no_readable_text(): void
    {
        $svg = app(LoginCaptchaService::class)->renderSvg('ab23k');

        $this->assertStringNotContainsString('<text', $svg);
        $this->assertStringNotContainsString('ab23k', $svg);
        $this->assertStringContainsString('<polyline', $svg);
    }

    public function test_update_progress_rejects_bad_tokens_and_non_owners(): void
    {
        $owner = $this->makeAdmin();
        $other = $this->makeAdmin();

        $this->actingAs($owner)->get('/admin/updates/progress/..%2F..%2Fetc%2Fpasswd')->assertNotFound();
        $this->actingAs($owner)->getJson(route('admin.updates.progress', str_repeat('a', 32)))->assertNotFound();
        $this->actingAs($other)->getJson(route('admin.updates.progress', str_repeat('a', 32)))->assertForbidden();
    }
}
