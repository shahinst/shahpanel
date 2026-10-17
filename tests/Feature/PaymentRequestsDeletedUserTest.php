<?php

namespace Tests\Feature;

use App\Models\WalletAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class PaymentRequestsDeletedUserTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_the_page_opens_when_a_charged_agent_was_deleted(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        WalletAdjustment::query()->create([
            'user_id' => $agent->id,
            'admin_user_id' => $admin->id,
            'amount' => '50000.00',
            'direction' => 'credit',
            'scope' => 'wallet',
            'note' => 'old charge',
        ]);

        // Users are soft-deleted, so the charge record outlives its agent with no one to point at.
        $agent->delete();

        $this->actingAs($admin)
            ->get(route('admin.payment-requests.index'))
            ->assertOk()
            ->assertSee(__('ui.deleted_user'))
            ->assertSee('old charge');
    }
}
