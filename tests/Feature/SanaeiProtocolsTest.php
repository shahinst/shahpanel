<?php

namespace Tests\Feature;

use App\Enums\AccountCategory;
use App\Enums\ServiceType;
use App\Services\SanaeiService;
use ReflectionMethod;
use Tests\TestCase;

class SanaeiProtocolsTest extends TestCase
{
    public function test_every_per_client_protocol_of_3x_ui_has_its_own_type(): void
    {
        $service = $this->app->make(SanaeiService::class);

        foreach ([
            'vmess' => ServiceType::SanaeiVmess,
            'vless' => ServiceType::SanaeiVless,
            'trojan' => ServiceType::SanaeiTrojan,
            'shadowsocks' => ServiceType::SanaeiShadowsocks,
            'hysteria' => ServiceType::SanaeiHysteria,
            'hysteria2' => ServiceType::SanaeiHysteria,
            'tuic' => ServiceType::SanaeiTuic,
            'wireguard' => ServiceType::SanaeiWireguard,
            'amneziawg' => ServiceType::SanaeiAmneziawg,
            'mtproto' => ServiceType::SanaeiMtproto,
        ] as $protocol => $expected) {
            $this->assertSame($expected, $service->serviceTypeFromProtocol($protocol), $protocol);
            $this->assertTrue($expected->isSanaei(), $protocol);
            $this->assertSame(AccountCategory::V2ray, $expected->accountCategory(), $protocol);
        }

        // Nothing 3x-ui serves per client: no type of its own.
        $this->assertNull(ServiceType::fromSanaeiProtocol('mixed'));

        // The panel's own WireGuard stays a MikroTik account.
        $this->assertSame(AccountCategory::Wireguard, ServiceType::Wireguard->accountCategory());
        $this->assertFalse(ServiceType::Wireguard->isSanaei());
    }

    public function test_an_update_keeps_the_credentials_the_panel_does_not_manage(): void
    {
        $service = $this->app->make(SanaeiService::class);
        $build = new ReflectionMethod($service, 'buildClientPayloadForApi');

        $existing = [
            'id' => 'c0ffee00-0000-4000-8000-000000000001',
            'email' => 'tuic-user',
            'password' => 'tuic-secret',
            'auth' => 'hy2-auth',
            'secret' => 'ee0123',
            'privateKey' => 'wg-private',
            'totalGB' => 0,
        ];

        $payload = $build->invoke($service, $existing, $existing['id'], ['totalGB' => 20]);

        // Losing any of these on a renewal broke the customer's config.
        $this->assertSame('tuic-secret', $payload['password']);
        $this->assertSame('hy2-auth', $payload['auth']);
        $this->assertSame('ee0123', $payload['secret']);
        $this->assertSame('wg-private', $payload['privateKey']);
        $this->assertSame(20 * 1024 ** 3, $payload['totalGB']);
        $this->assertSame('tuic-user', $payload['email']);
    }
}
