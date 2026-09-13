<?php

namespace App\Services;

use App\Models\Server;
use Throwable;

/**
 * L2TP/IPsec shared secret for MikroTik servers. RouterOS does not return
 * password-type fields (ipsec-secret) on API /print, so the panel keeps a
 * copy for display on PPP/L2TP account detail pages and optionally pushes it
 * back to /interface/l2tp-server/server when saved here.
 */
class ServerL2tpIpsecService
{
    public function __construct(protected MikrotikService $mikrotik)
    {
    }

    public function hasSecret(Server $server): bool
    {
        return trim((string) ($server->l2tp_ipsec_secret_enc ?? '')) !== '';
    }

    /**
     * Secret shown on L2TP account detail pages (panel copy first, then a
     * best-effort live read for routers that do return the field).
     */
    public function resolveSecretForDisplay(Server $server): string
    {
        $stored = trim((string) ($server->l2tp_ipsec_secret_enc ?? ''));

        if ($stored !== '') {
            return $stored;
        }

        return $this->readSecretFromRouter($server);
    }

    public function usesIpsec(Server $server): bool
    {
        if ($server->l2tp_use_ipsec === true || $this->hasSecret($server)) {
            return true;
        }

        if ($server->l2tp_use_ipsec === false) {
            return false;
        }

        return $this->l2tpServerUsesIpsec($this->mikrotik->getL2tpServerConfig($server));
    }

    /**
     * Persist panel copy and optionally mirror to the router L2TP server.
     */
    public function store(Server $server, ?string $secret, ?bool $useIpsec = null, bool $pushToRouter = true): void
    {
        $attributes = [];

        if ($secret !== null) {
            $attributes['l2tp_ipsec_secret_enc'] = trim($secret) === '' ? null : trim($secret);
        }

        if ($useIpsec !== null) {
            $attributes['l2tp_use_ipsec'] = $useIpsec;
        }

        if ($attributes !== []) {
            $server->update($attributes);
            $server->refresh();
        }

        if (! $pushToRouter || ! $server->isMikrotik()) {
            return;
        }

        $effectiveSecret = trim((string) ($server->l2tp_ipsec_secret_enc ?? ''));

        if ($effectiveSecret === '') {
            return;
        }

        $enabled = $useIpsec ?? $server->l2tp_use_ipsec ?? true;

        $this->pushToRouter($server, $effectiveSecret, (bool) $enabled);
    }

    /**
     * Sync use-ipsec flag (and secret when the API returns it) from the router.
     */
    public function syncFromRouter(Server $server): void
    {
        if (! $server->isMikrotik()) {
            return;
        }

        try {
            $config = $this->mikrotik->getL2tpServerConfig($server);
        } catch (Throwable) {
            return;
        }

        if ($config === null) {
            return;
        }

        $attributes = [
            'l2tp_use_ipsec' => $this->l2tpServerUsesIpsec($config),
        ];

        if (! $this->hasSecret($server)) {
            $liveSecret = trim((string) ($config['ipsec-secret'] ?? ''));

            if ($liveSecret !== '') {
                $attributes['l2tp_ipsec_secret_enc'] = $liveSecret;
            }
        }

        $server->update($attributes);
    }

    protected function readSecretFromRouter(Server $server): string
    {
        try {
            $config = $this->mikrotik->getL2tpServerConfig($server);

            return trim((string) ($config['ipsec-secret'] ?? ''));
        } catch (Throwable) {
            return '';
        }
    }

    protected function pushToRouter(Server $server, string $secret, bool $useIpsec): void
    {
        $payload = array_filter([
            'enabled' => 'yes',
            'use-ipsec' => $useIpsec ? 'yes' : 'no',
            'ipsec-secret' => $secret,
        ], fn ($value) => $value !== null && $value !== '');

        $this->mikrotik->sendCommand($server, '/interface/l2tp-server/server/set', $payload);
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    protected function l2tpServerUsesIpsec(?array $config): bool
    {
        if ($config === null) {
            return false;
        }

        $value = strtolower((string) ($config['use-ipsec'] ?? ''));

        return in_array($value, ['yes', 'true', 'required'], true);
    }
}
