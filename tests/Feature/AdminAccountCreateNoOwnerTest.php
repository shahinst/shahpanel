<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class AdminAccountCreateNoOwnerTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_the_admin_is_told_to_create_an_agent_when_there_is_none(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.accounts.create'))
            ->assertOk()
            ->assertSee(__('ui.no_account_owners'))
            ->assertSee(route('admin.users.create'), false);

        $this->makeAgent();

        $this->actingAs($admin)
            ->get(route('admin.accounts.create'))
            ->assertOk()
            ->assertDontSee(__('ui.no_account_owners'));
    }
}
