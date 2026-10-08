<?php

namespace Tests\Feature;

use App\Services\ServerSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class AccountCreateNoReachableServerTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_an_unreachable_server_is_a_form_error_not_a_crash(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        [$package, $duration] = $this->makePackage();
        $this->assignPackage($agent, $package);
        $this->app->instance(ServerSelectionService::class, \Mockery::mock(ServerSelectionService::class, fn ($m) => $m->shouldReceive('pickLeastBusyForPackage')->andThrow(new \RuntimeException(__('packages.no_reachable_servers')))));

        $this->actingAs($admin)
            ->from(route('admin.accounts.create'))
            ->post(route('admin.accounts.store'), [
                'owner_seller_id' => $agent->id,
                'package_id' => $package->id,
                'package_duration_id' => $duration->id,
                'client_mode' => 'display_name',
                'account_display_name' => 'Test',
            ])
            ->assertRedirect(route('admin.accounts.create'))
            ->assertSessionHas('error', __('packages.no_reachable_servers'));
    }
}
