<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_login_page_renders(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('author-links--auth', false);
    }

    public function test_each_role_reaches_its_dashboard(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $seller = $this->makeSeller($agent);
        $client = $this->makeClient($seller);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($agent)->get(route('agent.dashboard'))->assertOk();
        $this->actingAs($seller)->get(route('seller.dashboard'))->assertOk();
        $this->actingAs($client)->get(route('client.dashboard'))->assertOk();
    }

    public function test_main_admin_pages_render(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $seller = $this->makeSeller($agent);
        $this->makeClient($seller);
        $server = $this->makeServer();
        $this->makeAccount($agent, $server);

        foreach (['admin.users.index', 'admin.sellers.index', 'admin.clients.index', 'admin.servers.index',
            'admin.packages.index', 'admin.accounts.wireguard', 'admin.settings.index'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }

        $this->actingAs($admin)->get(route('admin.servers.show', $server))->assertOk();
    }

    public function test_scheduler_and_commands_boot(): void
    {
        $this->artisan('schedule:list')->assertExitCode(0);
    }
}
