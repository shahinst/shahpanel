<?php

namespace Tests\Feature;

use App\Enums\ServiceType;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class PortalPppTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_the_portal_shows_a_ppp_account_how_to_connect(): void
    {
        // Opening the portal syncs usage from the router; there is none here.
        $this->app->instance(SyncService::class, Mockery::mock(SyncService::class)->shouldIgnoreMissing());

        $account = $this->makeAccount($this->makeAgent(), $this->makeServer(), [
            'service_type' => ServiceType::L2tp,
            'remote_username' => 'ppp-ali',
            'remote_password_enc' => 'S3cret-pass',
            'portal_token_expires_at' => now()->addDay(),
        ]);
        $token = $account->portal_token;
        $solved = [hash_hmac('sha256', $token, (string) config('app.key')) => now()->addHour()->getTimestamp()];

        $this->withSession(['portal_captcha_solved' => $solved])
            ->get(route('portal.show', $token))
            ->assertOk()
            ->assertSee(__('accounts.connection_info'))
            ->assertSee('ppp-ali')
            ->assertSee('S3cret-pass');
    }

    public function test_the_portal_does_not_hand_out_an_openvpn_file_without_the_captcha(): void
    {
        $account = $this->makeAccount($this->makeAgent(), $this->makeServer(), [
            'service_type' => ServiceType::Openvpn,
            'portal_token_expires_at' => now()->addDay(),
        ]);

        $this->get(route('portal.ovpn.download', $account->portal_token))->assertRedirect();
    }
}
