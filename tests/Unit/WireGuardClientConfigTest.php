<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Services\MikrotikService;
use Tests\TestCase;

class WireGuardClientConfigTest extends TestCase
{
    public function test_config_uses_client_private_key_and_server_public_key(): void
    {
        config([
            'vpnpanel.wireguard.dns' => '9.9.9.9',
            'vpnpanel.wireguard.allowed_ips' => '0.0.0.0/0',
            'vpnpanel.wireguard.persistent_keepalive' => 10,
        ]);

        $server = new Server([
            'host' => '7.7.7.7',
            'wireguard_persistent_keepalive' => 10,
        ]);
        $service = new MikrotikService;

        $config = $service->buildClientConfig(
            $server,
            'CLIENT_PRIVATE_KEY',
            '10.10.0.5/32',
            'SERVER_PUBLIC_KEY',
            ['listen_port' => 51820],
        );

        // [Interface] holds the CLIENT private key.
        $this->assertStringContainsString("[Interface]\nPrivateKey = CLIENT_PRIVATE_KEY", $config);
        // [Peer] holds the SERVER public key.
        $this->assertMatchesRegularExpression('/\[Peer\]\nPublicKey = SERVER_PUBLIC_KEY/', $config);

        // Config-driven DNS + AllowedIPs.
        $this->assertStringContainsString('DNS = 9.9.9.9', $config);
        $this->assertStringContainsString('AllowedIPs = 0.0.0.0/0', $config);

        // Full .conf endpoint.
        $this->assertStringContainsString('Endpoint = 7.7.7.7:51820', $config);
        $this->assertStringContainsString('PersistentKeepalive = 10', $config);
    }

    public function test_format_persistent_keepalive_for_routeros(): void
    {
        $this->assertSame('00:00:10', MikrotikService::formatPersistentKeepalive(10));
        $this->assertSame('00:01:30', MikrotikService::formatPersistentKeepalive(90));
        $this->assertSame('00:00:00', MikrotikService::formatPersistentKeepalive(0));
    }

    public function test_server_wireguard_keepalive_override(): void
    {
        $server = new Server(['wireguard_persistent_keepalive' => 25]);
        $this->assertSame(25, $server->wireguardPersistentKeepalive());
    }

    public function test_generated_keys_are_valid_curve25519_pair(): void
    {
        $service = new MikrotikService;
        $keys = $service->generateKeys();

        $this->assertArrayHasKey('private_key', $keys);
        $this->assertArrayHasKey('public_key', $keys);

        // base64-encoded 32-byte keys.
        $this->assertSame(32, strlen(base64_decode($keys['private_key'], true)));
        $this->assertSame(32, strlen(base64_decode($keys['public_key'], true)));

        // Public key derives from the private key (direction sanity).
        $priv = base64_decode($keys['private_key']);
        $expectedPub = base64_encode(sodium_crypto_scalarmult_base($priv));
        $this->assertSame($expectedPub, $keys['public_key']);
    }
}
