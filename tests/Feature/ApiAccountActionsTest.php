<?php

namespace Tests\Feature;

use App\Services\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class ApiAccountActionsTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_a_reseller_manages_accounts_and_customers_over_the_api(): void
    {
        $agent = $this->makeAgent();
        ['plain_text' => $token] = app(ApiTokenService::class)->issue($agent, 'bot');
        [$package] = $this->makePackage();
        $account = $this->makeAccount($agent, $this->makeServer(), ['package_id' => $package->id, 'expiry_at' => now()->addDay()]);
        $api = fn () => $this->withToken($token);

        $api()->patchJson('/api/v1/accounts/'.$account->id, ['display_label' => 'Phone', 'auto_renew' => true])
            ->assertOk();
        $this->assertSame('Phone', $account->fresh()->display_label);
        $this->assertTrue((bool) $account->fresh()->auto_renew);

        $api()->getJson('/api/v1/accounts-expiring?days=2')->assertOk()->assertJsonPath('meta.count', 1);

        $created = $api()->postJson('/api/v1/clients', ['username' => 'ali_api'])->assertCreated()->json('data');
        $this->assertNotEmpty($created['password']);
        $api()->postJson('/api/v1/clients/'.$created['id'].'/accounts/'.$account->id)->assertOk();
        $this->assertSame($created['id'], (int) $account->fresh()->client_user_id);
        $api()->getJson('/api/v1/clients/'.$created['id'])->assertOk()->assertJsonCount(1, 'data.accounts');

        // Another reseller's account is out of reach.
        $foreign = $this->makeAccount($this->makeAgent(), $this->makeServer(), ['package_id' => $package->id]);
        $api()->patchJson('/api/v1/accounts/'.$foreign->id, ['display_label' => 'x'])->assertNotFound();
        $api()->deleteJson('/api/v1/accounts/'.$foreign->id)->assertNotFound();
    }

    public function test_a_read_only_token_cannot_change_anything(): void
    {
        $agent = $this->makeAgent();
        ['plain_text' => $token] = app(ApiTokenService::class)->issue($agent, 'reader', ['accounts:read']);
        [$package] = $this->makePackage();
        $account = $this->makeAccount($agent, $this->makeServer(), ['package_id' => $package->id]);

        $this->withToken($token)->patchJson('/api/v1/accounts/'.$account->id, ['display_label' => 'x'])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/clients', ['username' => 'nope'])->assertForbidden();
    }
}
