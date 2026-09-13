<?php

namespace App\Services;

use App\Models\Server;
use App\Services\Pasarguard\PasarguardGroupCatalog;
use App\Services\Pasarguard\PasarguardPanelClient;
use App\Services\Pasarguard\PasarguardUserPayloadBuilder;
use Illuminate\Support\Facades\Log;
use Throwable;

class PasarguardService
{
    public function __construct(
        protected PasarguardUserPayloadBuilder $payloadBuilder,
    ) {}
    /** @var array<int, PasarguardPanelClient> */
    protected array $clients = [];

    public function testConnection(Server $server): bool
    {
        return $this->testConnectionDetails($server)['ok'];
    }

    /**
     * @return array{ok: bool, message: string, error?: string, panel_url?: string, admin_username?: string, panel_version?: string, inbound_count?: int, group_count?: int, user_count?: int, permissions?: array<string, bool>, debug?: list<array<string, mixed>>}
     */
    public function testConnectionDetails(Server $server): array
    {
        try {
            $result = $this->client($server)->testConnection();
            $this->persistGroupsFromTestResult($server, $result);

            return $result;
        } catch (Throwable $exception) {
            Log::channel('pasarguard')->warning('PasarGuard connection test failed', [
                'server_id' => $server->id,
                'host' => $server->host,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => 'اتصال به پنل PasarGuard ناموفق بود.',
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return list<string>
     */
    public function listInboundTags(Server $server): array
    {
        return $this->client($server)->listInboundTags();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listGroups(Server $server): array
    {
        return $this->client($server)->listGroups();
    }

    /**
     * @return list<array{id: int, name: string, inbound_tags: list<string>}>
     */
    public function storedGroups(Server $server): array
    {
        return PasarguardGroupCatalog::forServer($server);
    }

    /**
     * @param  array<string, mixed>  $testResult
     */
    public function persistGroupsFromTestResult(Server $server, array $testResult): void
    {
        if (! ($testResult['ok'] ?? false)) {
            return;
        }

        $groups = $testResult['groups'] ?? null;
        if (! is_array($groups) || $groups === []) {
            return;
        }

        $normalized = PasarguardGroupCatalog::normalizeList($groups);

        if ($normalized === []) {
            return;
        }

        $server->update([
            'pasarguard_groups' => $normalized,
            'pasarguard_groups_synced_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSystem(Server $server): array
    {
        return $this->client($server)->getSystem();
    }

    /**
     * Short-timeout system read for dashboard monitor.
     *
     * @return array<string, mixed>
     */
    public function getSystemForMonitor(Server $server): array
    {
        $timeout = max(1, (int) config('vpnpanel.server_monitor.pasarguard_timeout_seconds', 5));

        return $this->client($server)->getSystem($timeout);
    }

    public function client(Server $server): PasarguardPanelClient
    {
        return $this->clients[$server->id] ??= new PasarguardPanelClient($server);
    }

    public function payloadBuilder(): PasarguardUserPayloadBuilder
    {
        return $this->payloadBuilder;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUser(Server $server, string $username): array
    {
        $payload = $this->client($server)->getUser($username);

        return is_array($payload['user'] ?? null) ? $payload['user'] : $payload;
    }

    /**
     * Daily traffic breakdown from PasarGuard's own usage API ("مصرف روزانه").
     * This is the authoritative source the panel itself charts.
     *
     * @return array{total_bytes: int, daily: array<int, array{date: string, bytes: int}>}
     */
    public function getUserUsageDaily(Server $server, string $username, int $days = 90): array
    {
        $start = now()->subDays(max(1, $days))->startOfDay();
        $end = now()->endOfDay();

        $raw = $this->client($server)->getUserUsage(
            $username,
            $start->toIso8601String(),
            $end->toIso8601String(),
            'day',
        );

        return $this->normalizeUsageStats($raw);
    }

    /**
     * Normalises both the new PasarGuard shape ({stats: {node_id: [{total_traffic, period_start}]}})
     * and the older Marzban shape ({usages: [{used_traffic, period_start}]}).
     *
     * @param  array<string, mixed>  $raw
     * @return array{total_bytes: int, daily: array<int, array{date: string, bytes: int}>}
     */
    public function normalizeUsageStats(array $raw): array
    {
        /** @var array<string, int> $daily */
        $daily = [];
        $total = 0;

        $accumulate = function (array $entry) use (&$daily, &$total): void {
            $bytes = max(0, (int) (
                $entry['total_traffic']
                ?? $entry['used_traffic']
                ?? 0
            ));

            if ($bytes <= 0) {
                return;
            }

            $total += $bytes;
            $date = $this->usageDateKey($entry['period_start'] ?? null);

            if ($date !== null) {
                $daily[$date] = ($daily[$date] ?? 0) + $bytes;
            }
        };

        $stats = $raw['stats'] ?? null;
        if (is_array($stats)) {
            foreach ($stats as $entries) {
                if (! is_array($entries)) {
                    continue;
                }
                foreach ($entries as $entry) {
                    if (is_array($entry)) {
                        $accumulate($entry);
                    }
                }
            }
        } elseif (is_array($raw['usages'] ?? null)) {
            foreach ($raw['usages'] as $entry) {
                if (is_array($entry)) {
                    $accumulate($entry);
                }
            }
        }

        ksort($daily);

        $dailyList = [];
        foreach ($daily as $date => $bytes) {
            $dailyList[] = ['date' => $date, 'bytes' => $bytes];
        }

        return [
            'total_bytes' => $total,
            'daily' => $dailyList,
        ];
    }

    protected function usageDateKey(mixed $periodStart): ?string
    {
        if (! is_string($periodStart) || $periodStart === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($periodStart)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAllUsers(Server $server): array
    {
        $limit = max(50, (int) config('vpnpanel.pasarguard.users_page_size', 200));
        $offset = 0;
        $all = [];

        do {
            $page = $this->client($server)->getUsers(offset: $offset, limit: $limit);
            $batch = $page['users'] ?? [];
            if (! is_array($batch)) {
                break;
            }

            foreach ($batch as $row) {
                if (is_array($row)) {
                    $all[] = $row;
                }
            }

            $total = (int) ($page['total'] ?? count($all));
            $offset += $limit;
        } while (count($batch) === $limit && $offset < $total);

        return $all;
    }

    /**
     * @return array<string, mixed>
     */
    public function createPanelUser(
        Server $server,
        \App\Models\Package $package,
        \App\Models\PackageDuration $duration,
        string $username,
        ?int $dataLimitBytes,
        ?\Illuminate\Support\Carbon $expiryAt,
    ): array {
        $payload = $this->payloadBuilder->buildCreate(
            $package,
            $duration,
            $username,
            $dataLimitBytes,
            $expiryAt,
        );

        $response = $this->client($server)->createUser($payload);

        return is_array($response['user'] ?? null) ? $response['user'] : $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function modifyPanelUser(
        Server $server,
        string $username,
        \App\Models\Package $package,
        \App\Models\PackageDuration $duration,
        ?int $dataLimitBytes,
        ?\Illuminate\Support\Carbon $expiryAt,
        bool $shouldEnable,
    ): array {
        $payload = $this->payloadBuilder->buildUpdate(
            $package,
            $duration,
            $dataLimitBytes,
            $expiryAt,
            $shouldEnable,
        );

        $response = $this->client($server)->modifyUser($username, $payload);

        return is_array($response['user'] ?? null) ? $response['user'] : $response;
    }

    /**
     * Disable/enable without requiring package/duration (safe for sync quota-exhaustion).
     *
     * @return array<string, mixed>
     */
    public function setUserEnabled(Server $server, string $username, bool $enabled): array
    {
        $response = $this->client($server)->modifyUser($username, [
            'status' => $enabled ? 'active' : 'disabled',
        ]);

        return is_array($response['user'] ?? null) ? $response['user'] : $response;
    }

    public function removePanelUser(Server $server, string $username): void
    {
        $this->client($server)->removeUser($username);
    }

    /**
     * @return array<string, mixed>
     */
    public function resetUserTraffic(Server $server, string $username): array
    {
        return $this->client($server)->resetUserTraffic($username);
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array{up: int, down: int, used_bytes: int, limit_bytes: ?int, remaining_bytes: ?int, lifetime_used_bytes: int}
     */
    public function normalizeTrafficSnapshot(array $remote): array
    {
        // IMPORTANT: only the current-period counter (`used_traffic`) feeds `used_bytes`.
        // Never fall back to `lifetime_used_traffic` here — mixing the two makes the
        // per-sync delta jump between 0 and the lifetime total, which historically
        // inflated AccountUsageLog by ~10x. lifetime is reported separately, for display only.
        $used = max(0, (int) (
            $remote['used_traffic']
            ?? $remote['usedTraffic']
            ?? 0
        ));

        $lifetimeUsed = max(0, (int) (
            $remote['lifetime_used_traffic']
            ?? $remote['lifetimeUsedTraffic']
            ?? $used
        ));

        $limitRaw = $remote['data_limit'] ?? $remote['dataLimit'] ?? null;
        $limit = ($limitRaw !== null && (int) $limitRaw > 0) ? (int) $limitRaw : null;

        return [
            'up' => 0,
            'down' => $used,
            'used_bytes' => $used,
            'limit_bytes' => $limit,
            'remaining_bytes' => $limit !== null ? max(0, $limit - $used) : null,
            'lifetime_used_bytes' => $lifetimeUsed,
        ];
    }
}
