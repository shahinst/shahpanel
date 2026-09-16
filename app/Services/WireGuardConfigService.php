<?php

namespace App\Services;

use App\Exceptions\RemoteProvisionException;
use App\Models\Account;
use App\Models\Server;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Response;

class WireGuardConfigService
{
    public function __construct(
        protected MikrotikService $mikrotikService,
    ) {}

    public function buildConfig(Account $account): string
    {
        $account->loadMissing(['server']);

        $server = $account->server;
        $privateKey = $account->wireguard_private_key_enc;
        $address = $account->wireguard_address;

        if ($server === null || $privateKey === null || $address === null || $account->wireguard_public_key === null) {
            throw new RemoteProvisionException(__('services.wireguard_account_incomplete'));
        }

        $publicKey = $account->wireguard_public_key;
        $interface = $this->mikrotikService->wireguardPeerInterface($server, $publicKey)
            ?? $this->mikrotikService->resolveWireguardInterfaceName($server, $account->mikrotik_profile_key);
        $details = $this->mikrotikService->getWireguardInterfaceDetails($server, $interface);

        if ($details === null) {
            throw new RemoteProvisionException(__('services.wireguard_interface_pubkey_missing'));
        }

        return $this->mikrotikService->buildClientConfig(
            $server,
            $privateKey,
            $address,
            $details['public_key'],
            [
                'endpoint' => $server->vpnClientEndpointHost().':'.$details['listen_port'],
                'listen_port' => $details['listen_port'],
                'persistent_keepalive' => $server->wireguardPersistentKeepalive(),
            ]
        );
    }

    /**
     * WhatsApp-safe WireGuard QR: high ECC, large modules, opaque white
     * background, generous quiet zone. JPEG is never used (lossy).
     */
    public function buildQrPng(Account $account): string
    {
        $config = $this->buildConfig($account);

        $result = Builder::create()
            ->writer(new PngWriter)
            ->writerOptions([
                // Force truecolor opaque PNG — alpha/paletted PNGs often break in WhatsApp.
                'compression_level' => 6,
            ])
            ->data($config)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->size(720)
            ->margin(28)
            ->foregroundColor(new Color(0, 0, 0))
            ->backgroundColor(new Color(255, 255, 255))
            ->build();

        return $result->getString();
    }

    public function configFilename(Account $account): string
    {
        return $this->safeFilename($account->remote_username).'.conf';
    }

    public function qrFilename(Account $account): string
    {
        return $this->safeFilename($account->remote_username).'-wireguard-qr.png';
    }

    /** Binary PNG download headers that survive chat-app re-sharing as a file. */
    public function qrDownloadResponse(Account $account): Response
    {
        $png = $this->buildQrPng($account);
        $filename = $this->qrFilename($account);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($png),
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function safeFilename(?string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $name) ?: 'wireguard';

        return trim($clean, '._-') ?: 'wireguard';
    }
}
