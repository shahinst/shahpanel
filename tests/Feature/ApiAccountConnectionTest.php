<?php

namespace Tests\Feature;

use App\Enums\ServiceType;
use App\Services\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class ApiAccountConnectionTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_an_anyconnect_account_comes_with_its_address_password_and_a_live_portal_link(): void
    {
        $agent = $this->makeAgent();
        ['plain_text' => $token] = app(ApiTokenService::class)->issue($agent, 'bot');
        [$package] = $this->makePackage();
        $server = $this->makeServer('ocserv', ['ocserv_vpn_address' => 'vpn.example']);
        $account = $this->makeAccount($agent, $server, [
            'package_id' => $package->id, 'service_type' => ServiceType::Ocserv,
            'remote_password_enc' => 'p4ss', 'portal_token' => 'dead', 'portal_token_expires_at' => now()->subDay(),
        ]);

        $data = $this->withToken($token)->getJson('/api/v1/accounts/'.$account->id)->assertOk()->json('data');

        $this->assertSame('vpn.example', $data['connection']['server_address']);
        $this->assertSame('p4ss', $data['connection']['password']);
        $this->assertNotSame('dead', $data['portal']['token']);
        $this->assertStringEndsWith('/'.$data['portal']['token'], $data['portal']['url']);
        $this->assertTrue(now()->lt($data['portal']['expires_at']));

        // The same link is handed out again while it lives.
        $again = $this->withToken($token)->getJson('/api/v1/accounts/'.$account->id)->json('data.portal.token');
        $this->assertSame($data['portal']['token'], $again);
    }

    public function test_a_ppp_account_carries_the_server_ovpn_profile(): void
    {
        $agent = $this->makeAgent();
        ['plain_text' => $token] = app(ApiTokenService::class)->issue($agent, 'bot');
        [$package] = $this->makePackage();
        Storage::disk('local')->put('ovpn/t.ovpn', "client\nremote vpn.example 1194\n");
        $server = $this->makeServer('mikrotik', ['ovpn_profile_path' => 'ovpn/t.ovpn', 'ovpn_profile_original_name' => 'mine.ovpn']);
        $account = $this->makeAccount($agent, $server, ['package_id' => $package->id, 'service_type' => ServiceType::Openvpn, 'remote_password_enc' => 'x']);

        $ovpn = $this->withToken($token)->getJson('/api/v1/accounts/'.$account->id)->json('data.connection.ovpn');
        $this->assertSame('mine.ovpn', $ovpn['filename']);
        $this->assertStringContainsString('remote vpn.example', $ovpn['content']);

        $this->withToken($token)->get('/api/v1/accounts/'.$account->id.'/ovpn')->assertOk()->assertDownload('mine.ovpn');
        Storage::disk('local')->delete('ovpn/t.ovpn');
    }
}
