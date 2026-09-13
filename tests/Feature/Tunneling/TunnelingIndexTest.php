<?php

namespace Tests\Feature\Tunneling;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TunnelingIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_load_tunneling_index(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.tunneling.index'))
            ->assertOk()
            ->assertSee(__('tunneling.title'), false);
    }
}
