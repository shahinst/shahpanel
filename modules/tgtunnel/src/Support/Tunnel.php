<?php

namespace Modules\TgTunnel\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The panel's side of panel-tgtunnel, the root helper that owns the tunnel.
 *
 * The panel never touches WireGuard itself: it passes the config on stdin and
 * reads JSON back. Everything that needs root -- and the rule that only the
 * key, address and endpoint of a config are ever used -- lives in the helper.
 */
class Tunnel
{
    public const HELPER = '/usr/local/sbin/panel-tgtunnel';

    private const STATUS_KEY = 'tgtunnel.status';

    public function installed(): bool
    {
        return is_file(self::HELPER);
    }

    /**
     * @return array{configured: bool, active: bool, handshake_age: int, rx: int, tx: int, endpoint: string, telegram_via: string, healthy: bool}|null
     */
    public function status(bool $fresh = false): ?array
    {
        if ($fresh) {
            Cache::forget(self::STATUS_KEY);
        }

        // The dashboard asks on every load; one sudo call per 20 seconds is plenty.
        return Cache::remember(self::STATUS_KEY, 20, function (): ?array {
            $data = $this->call(['status']);

            if ($data === null) {
                return null;
            }

            // Up means: the service runs, the peer answered within three
            // minutes, and Telegram's address is actually routed into it.
            $data['healthy'] = ($data['active'] ?? false)
                && ($data['handshake_age'] ?? -1) >= 0
                && ($data['handshake_age'] ?? -1) <= 180
                && ($data['telegram_via'] ?? '') === 'tgtun';

            return $data;
        });
    }

    public function apply(string $config): void
    {
        $result = Process::timeout(120)->input($config)->run(['sudo', '-n', self::HELPER, 'apply']);
        Cache::forget(self::STATUS_KEY);

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: trim($result->output()) ?: 'panel-tgtunnel failed');
        }
    }

    public function down(): void
    {
        $this->call(['down'], json: false);
        Cache::forget(self::STATUS_KEY);
    }

    /**
     * @return array{ok: bool, http?: string, bytes_received?: int, telegram_via?: string, error?: string}|null
     */
    public function test(): ?array
    {
        return $this->call(['test'], timeout: 30);
    }

    /**
     * @param  list<string>  $args
     */
    protected function call(array $args, bool $json = true, int $timeout = 15): ?array
    {
        if (! $this->installed()) {
            return null;
        }

        $result = Process::timeout($timeout)->run(array_merge(['sudo', '-n', self::HELPER], $args));

        if (! $result->successful()) {
            return null;
        }

        return $json ? json_decode(trim($result->output()), true) : [];
    }
}
