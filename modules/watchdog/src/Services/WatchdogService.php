<?php

namespace Modules\Watchdog\Services;

use App\Enums\ServerType;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Ocserv\OcservClient;
use App\Services\ServerBackup\ServerBackupTelegramNotifier;
use App\Support\ServerBackupTelegramSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Checks what silently breaks a panel and tells the admin on the Telegram
 * backup bot: servers the panel cannot reach, the panel's disk running full,
 * SSL certificates about to expire and AnyConnect servers running out of
 * client addresses.
 *
 * A problem is announced once, again every few hours while it lasts, and its
 * end is announced too. A server must fail twice in a row before it counts as
 * down, so one lost packet does not wake anybody up.
 */
class WatchdogService
{
    public const REPEAT_SECONDS = 6 * 3600;

    public const FAILS_BEFORE_DOWN = 2;

    public const LAST_RUN_KEY = 'watchdog:last-run';

    public const DEFAULTS = [
        'watchdog_disk_percent' => 10,
        'watchdog_cert_days' => 14,
        'watchdog_pool_percent' => 90,
    ];

    public function __construct(protected ServerBackupTelegramNotifier $telegram) {}

    public static function threshold(string $key): int
    {
        return (int) Setting::getValue($key, (string) self::DEFAULTS[$key]);
    }

    /**
     * @return list<array{id: string, label: string, ok: bool, detail: string}>
     */
    public function run(): array
    {
        $servers = Server::query()->active()->orderBy('name')->get();

        $checks = array_merge(
            $this->serverChecks($servers),
            [$this->diskCheck()],
            $this->certChecks($servers),
            $this->poolChecks($servers),
        );

        foreach ($checks as $check) {
            $this->track($check);
        }

        Cache::put(self::LAST_RUN_KEY, ['at' => time(), 'checks' => $checks], now()->addDay());

        return $checks;
    }

    protected function serverChecks($servers): array
    {
        $checks = [];

        foreach ($servers as $server) {
            $host = $server->apiConnectionHost();
            $port = (int) $server->port;
            $failsKey = 'watchdog:fails:'.$server->id;
            $fails = $this->reachable($host, $port) ? 0 : (int) Cache::get($failsKey, 0) + 1;
            Cache::put($failsKey, $fails, now()->addHour());

            $checks[] = [
                'id' => 'server:'.$server->id,
                'label' => __('watchdog::watchdog.label_server', ['name' => $server->name]),
                'ok' => $fails < self::FAILS_BEFORE_DOWN,
                'detail' => __('watchdog::watchdog.server_down', ['name' => $server->name, 'host' => $host, 'port' => $port]),
            ];
        }

        return $checks;
    }

    protected function diskCheck(): array
    {
        [$free, $total] = $this->diskSpace();
        $percent = $total > 0 ? (int) floor($free * 100 / $total) : 100;

        return [
            'id' => 'disk',
            'label' => __('watchdog::watchdog.label_disk'),
            'ok' => $percent >= self::threshold('watchdog_disk_percent'),
            'detail' => __('watchdog::watchdog.disk_low', [
                'free' => $this->gigabytes($free),
                'total' => $this->gigabytes($total),
                'percent' => $percent,
            ]),
        ];
    }

    /**
     * The panel's own address and the address AnyConnect users connect to:
     * both are certificates a customer's device checks.
     */
    protected function certChecks($servers): array
    {
        $targets = [];
        $panel = parse_url((string) config('app.url'));

        if (($panel['scheme'] ?? '') === 'https' && ($panel['host'] ?? '') !== '') {
            $targets[$panel['host'].':'.($panel['port'] ?? 443)] = [$panel['host'], (int) ($panel['port'] ?? 443)];
        }

        foreach ($servers as $server) {
            if ($server->type === ServerType::Ocserv) {
                $host = trim((string) ($server->ocserv_vpn_address ?: $server->host));
                $targets[$host.':443'] = [$host, 443];
            }
        }

        $days = self::threshold('watchdog_cert_days');
        $checks = [];

        foreach ($targets as $id => [$host, $port]) {
            $expiresAt = $this->certificateExpiry($host, $port);

            // An unreadable certificate is the server check's business.
            if ($expiresAt === null) {
                continue;
            }

            $left = (int) floor(($expiresAt - time()) / 86400);
            $checks[] = [
                'id' => 'cert:'.$id,
                'label' => __('watchdog::watchdog.label_cert', ['host' => $host]),
                'ok' => $left >= $days,
                'detail' => __('watchdog::watchdog.cert_expiring', ['host' => $host, 'days' => max(0, $left), 'date' => date('Y-m-d', $expiresAt)]),
            ];
        }

        return $checks;
    }

    /**
     * Only agents that report their pool (the bundled ocserv-api does) are
     * checked; an ocserv out of addresses refuses every new login.
     */
    protected function poolChecks($servers): array
    {
        $percent = self::threshold('watchdog_pool_percent');
        $checks = [];

        foreach ($servers as $server) {
            if ($server->type !== ServerType::Ocserv) {
                continue;
            }

            $health = rescue(fn () => $this->ocservHealth($server), [], false);
            $size = (int) ($health['pool_size'] ?? 0);

            if ($size <= 0) {
                continue;
            }

            $used = (int) ($health['sessions'] ?? 0);
            $usedPercent = (int) floor($used * 100 / $size);
            $checks[] = [
                'id' => 'pool:'.$server->id,
                'label' => __('watchdog::watchdog.label_pool', ['name' => $server->name]),
                'ok' => $usedPercent < $percent,
                'detail' => __('watchdog::watchdog.pool_full', ['name' => $server->name, 'used' => $used, 'size' => $size, 'percent' => $usedPercent]),
            ];
        }

        return $checks;
    }

    protected function track(array $check): void
    {
        $key = 'watchdog:alert:'.$check['id'];
        $last = Cache::get($key);

        if ($check['ok']) {
            if ($last !== null) {
                Cache::forget($key);
                $this->send('✅ '.e(__('watchdog::watchdog.recovered', ['label' => $check['label']])));
            }

            return;
        }

        if ($last === null || time() - $last >= self::REPEAT_SECONDS) {
            Cache::put($key, time(), now()->addDays(7));
            $this->send('⚠️ '.e($check['detail']));
        }
    }

    protected function send(string $html): void
    {
        if (ServerBackupTelegramSettings::isConfigured()) {
            rescue(fn () => $this->telegram->sendMessage($html));
        }
    }

    protected function reachable(string $host, int $port): bool
    {
        if ($host === '' || $port <= 0) {
            return false;
        }

        $socket = @fsockopen($host, $port, $errno, $error, 5);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * @return array{0: float, 1: float}
     */
    protected function diskSpace(): array
    {
        return [(float) @disk_free_space(base_path()), (float) @disk_total_space(base_path())];
    }

    protected function certificateExpiry(string $host, int $port): ?int
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $socket = @stream_socket_client('ssl://'.$host.':'.$port, $errno, $error, 8, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            return null;
        }

        $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);
        $parsed = $certificate ? openssl_x509_parse($certificate) : false;

        return is_array($parsed) && isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
    }

    protected function ocservHealth(Server $server): array
    {
        return (new OcservClient($server))->health();
    }

    protected function gigabytes(float $bytes): string
    {
        return number_format($bytes / 1073741824, 1).' GB';
    }
}
