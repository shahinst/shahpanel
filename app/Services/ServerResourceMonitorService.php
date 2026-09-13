<?php

namespace App\Services;

use App\Enums\ServerHealthStatus;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ServerResourceMonitorService
{
    public function __construct(
        protected MikrotikService $mikrotikService,
        protected SanaeiService $sanaeiService,
        protected PasarguardService $pasarguardService,
    ) {}

    /**
     * Fast dashboard JSON — returns cached snapshots immediately and refreshes only a few stale servers per request.
     *
     * @return array{servers: list<array<string, mixed>>, polled_at: string, stale: bool}
     */
    public function dashboardPayload(): array
    {
        $servers = Server::query()
            ->where('is_active', true)
            ->when(
                Schema::hasColumn('servers', 'show_on_dashboard'),
                fn ($query) => $query->where('show_on_dashboard', true),
            )
            ->orderBy('name')
            ->get();

        $this->refreshStaleServers($servers, $this->refreshPerRequest());

        $snapshots = $servers
            ->map(fn (Server $server): array => $this->readCachedSnapshot($server))
            ->values()
            ->all();

        return [
            'servers' => $snapshots,
            'polled_at' => now()->toIso8601String(),
            'stale' => $this->hasStaleSnapshots($servers),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function snapshotAll(): array
    {
        return $this->dashboardPayload()['servers'];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Server $server): array
    {
        $cached = Cache::get($this->cacheKey($server));

        if (is_array($cached)) {
            return $cached;
        }

        $snapshot = $this->fetchSnapshot($server);
        $this->storeSnapshot($server, $snapshot);

        return $snapshot;
    }

    /**
     * @param  Collection<int, Server>  $servers
     */
    protected function refreshStaleServers(Collection $servers, int $max): void
    {
        if ($max <= 0 || $servers->isEmpty()) {
            return;
        }

        $candidates = $servers
            ->sortBy(fn (Server $server): int => (int) Cache::get($this->cachedAtKey($server), 0))
            ->take($max);

        foreach ($candidates as $server) {
            $snapshot = $this->fetchSnapshot($server);
            $this->storeSnapshot($server, $snapshot);
        }
    }

    /**
     * @param  Collection<int, Server>  $servers
     */
    protected function hasStaleSnapshots(Collection $servers): bool
    {
        $ttl = $this->cacheTtlSeconds();

        foreach ($servers as $server) {
            $cachedAt = Cache::get($this->cachedAtKey($server));

            if (! is_int($cachedAt) || (now()->timestamp - $cachedAt) >= $ttl) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function readCachedSnapshot(Server $server): array
    {
        $cached = Cache::get($this->cacheKey($server));

        if (is_array($cached)) {
            return $cached;
        }

        return $this->placeholderSnapshot($server);
    }

    /**
     * @return array<string, mixed>
     */
    protected function placeholderSnapshot(Server $server): array
    {
        $health = match ($server->last_health_status) {
            ServerHealthStatus::Healthy => 'healthy',
            ServerHealthStatus::Degraded => 'warning',
            ServerHealthStatus::Unreachable => 'offline',
            default => 'offline',
        };

        return [
            'id' => $server->id,
            'name' => $server->name,
            'host' => $server->host,
            'port' => $server->port,
            'type' => $server->type->value,
            'online' => false,
            'health' => $health,
            'cpu_percent' => null,
            'memory' => null,
            'disk' => null,
            'uptime' => null,
            'uptime_seconds' => null,
            'load' => [],
            'xray_state' => null,
            'xray_version' => null,
            'version' => null,
            'network' => null,
            'checked_at' => ($server->last_health_check_at ?? now())->toIso8601String(),
            'error' => null,
            'stale' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function storeSnapshot(Server $server, array $snapshot): void
    {
        $ttl = $this->cacheTtlSeconds();

        Cache::put($this->cacheKey($server), $snapshot, $ttl);
        Cache::put($this->cachedAtKey($server), now()->timestamp, $ttl);
    }

    protected function cacheKey(Server $server): string
    {
        return 'server-resource-monitor:'.$server->id;
    }

    protected function cachedAtKey(Server $server): string
    {
        return 'server-resource-monitor:at:'.$server->id;
    }

    protected function cacheTtlSeconds(): int
    {
        return max(15, (int) config('vpnpanel.server_monitor.cache_ttl_seconds', 60));
    }

    protected function refreshPerRequest(): int
    {
        return max(0, (int) config('vpnpanel.server_monitor.refresh_per_request', 2));
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchSnapshot(Server $server): array
    {
        $base = [
            'id' => $server->id,
            'name' => $server->name,
            'host' => $server->host,
            'port' => $server->port,
            'type' => $server->type->value,
            'online' => false,
            'health' => 'offline',
            'cpu_percent' => null,
            'memory' => null,
            'disk' => null,
            'uptime' => null,
            'uptime_seconds' => null,
            'load' => [],
            'xray_state' => null,
            'xray_version' => null,
            'version' => null,
            'network' => null,
            'checked_at' => now()->toIso8601String(),
            'error' => null,
            'stale' => false,
        ];

        try {
            $payload = match (true) {
                $server->isMikrotik() => $this->normalizeMikrotik($this->mikrotikService->getSystemResourceForMonitor($server)),
                $server->isPasarguard() => $this->normalizePasarguard($this->pasarguardService->getSystemForMonitor($server)),
                $server->isRemnawave() => ['health' => 'online'],
                $server->isSanaei() => $this->normalizeSanaei($this->sanaeiService->getServerStatusForMonitor($server)),
                default => throw new \InvalidArgumentException('نوع سرور برای مانیتور پشتیبانی نمی‌شود.'),
            };

            $server->update([
                'last_health_check_at' => now(),
                'last_health_status' => ServerHealthStatus::Healthy,
            ]);

            return array_merge($base, $payload, [
                'online' => true,
                'health' => $this->healthFromUsage($payload['cpu_percent'] ?? null, $payload['memory']['percent'] ?? null),
            ]);
        } catch (Throwable $exception) {
            $server->update([
                'last_health_check_at' => now(),
                'last_health_status' => ServerHealthStatus::Unreachable,
            ]);

            return array_merge($base, [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    protected function normalizeMikrotik(array $resource): array
    {
        $totalMemory = (int) ($resource['total-memory'] ?? 0);
        $freeMemory = (int) ($resource['free-memory'] ?? 0);
        $usedMemory = max(0, $totalMemory - $freeMemory);

        $totalDisk = (int) ($resource['total-hdd-space'] ?? 0);
        $freeDisk = (int) ($resource['free-hdd-space'] ?? 0);
        $usedDisk = max(0, $totalDisk - $freeDisk);

        return [
            'cpu_percent' => round((float) ($resource['cpu-load'] ?? 0), 1),
            'memory' => $this->usageBlock($usedMemory, $totalMemory),
            'disk' => $this->usageBlock($usedDisk, $totalDisk),
            'uptime' => (string) ($resource['uptime'] ?? '—'),
            'uptime_seconds' => $this->parseMikrotikUptime((string) ($resource['uptime'] ?? '')),
            'version' => (string) ($resource['version'] ?? '—'),
            'load' => [],
            'network' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    protected function normalizePasarguard(array $status): array
    {
        $memTotal = (int) ($status['mem_total'] ?? 0);
        $memUsed = (int) ($status['mem_used'] ?? 0);
        $diskTotal = (int) ($status['disk_total'] ?? 0);
        $diskUsed = (int) ($status['disk_used'] ?? 0);

        return [
            'cpu_percent' => round((float) ($status['cpu_usage'] ?? 0), 1),
            'memory' => $this->usageBlock($memUsed, $memTotal),
            'disk' => $this->usageBlock($diskUsed, $diskTotal),
            'uptime' => $this->formatDuration((int) ($status['uptime_seconds'] ?? 0)),
            'uptime_seconds' => (int) ($status['uptime_seconds'] ?? 0),
            'version' => (string) ($status['version'] ?? '—'),
            'load' => [],
            'network' => [
                'rx_bps' => (int) ($status['incoming_bandwidth_speed'] ?? 0),
                'tx_bps' => (int) ($status['outgoing_bandwidth_speed'] ?? 0),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    protected function normalizeSanaei(array $status): array
    {
        $memCurrent = (int) ($status['mem']['current'] ?? 0);
        $memTotal = (int) ($status['mem']['total'] ?? 0);
        $diskCurrent = (int) ($status['disk']['current'] ?? 0);
        $diskTotal = (int) ($status['disk']['total'] ?? 0);

        $loads = collect($status['loads'] ?? [])
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => round((float) $value, 2))
            ->values()
            ->all();

        $upBytes = (int) ($status['netIO']['up'] ?? 0);
        $downBytes = (int) ($status['netIO']['down'] ?? 0);
        $tcpCount = (int) ($status['tcpCount'] ?? 0);

        return [
            'cpu_percent' => round((float) ($status['cpu'] ?? 0), 1),
            'memory' => $this->usageBlock($memCurrent, $memTotal),
            'disk' => $this->usageBlock($diskCurrent, $diskTotal),
            'uptime' => $this->formatDuration((int) ($status['uptime'] ?? 0)),
            'uptime_seconds' => (int) ($status['uptime'] ?? 0),
            'load' => $loads,
            'tcp_count' => $tcpCount,
            'xray_state' => (string) ($status['xray']['state'] ?? 'unknown'),
            'xray_version' => (string) ($status['xray']['version'] ?? '—'),
            'version' => (string) ($status['xray']['version'] ?? '—'),
            'network' => $this->networkBlock($upBytes, $downBytes, $tcpCount),
        ];
    }

    /**
     * @return array{up: string, down: string, up_bytes: int, down_bytes: int, tcp_count: int, up_ratio: float, percent: float}
     */
    protected function networkBlock(int $upBytes, int $downBytes, int $tcpCount): array
    {
        $total = max(1, $upBytes + $downBytes);
        $upRatio = round(($upBytes / $total) * 100, 1);
        $throughputPercent = min(100, round((($upBytes + $downBytes) / (1024 * 1024)) * 100, 1));
        $connectionPercent = min(100, round(($tcpCount / 500) * 100, 1));
        $percent = max($throughputPercent, $connectionPercent);

        return [
            'up' => $this->formatBytes($upBytes),
            'down' => $this->formatBytes($downBytes),
            'up_bytes' => $upBytes,
            'down_bytes' => $downBytes,
            'tcp_count' => $tcpCount,
            'up_ratio' => $upRatio,
            'percent' => $percent,
        ];
    }

    /**
     * @return array{used: int, total: int, percent: float, used_label: string, total_label: string}
     */
    protected function usageBlock(int $used, int $total): array
    {
        $percent = $total > 0 ? round(($used / $total) * 100, 1) : 0.0;

        return [
            'used' => $used,
            'total' => $total,
            'percent' => $percent,
            'used_label' => $this->formatBytes($used),
            'total_label' => $this->formatBytes($total),
        ];
    }

    protected function healthFromUsage(?float $cpu, ?float $memoryPercent): string
    {
        $cpu = $cpu ?? 0;
        $memoryPercent = $memoryPercent ?? 0;

        if ($cpu >= 90 || $memoryPercent >= 95) {
            return 'critical';
        }

        if ($cpu >= 75 || $memoryPercent >= 85) {
            return 'warning';
        }

        return 'healthy';
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1).' GB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }

    protected function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];

        if ($days > 0) {
            $parts[] = $days.'روز';
        }

        if ($hours > 0) {
            $parts[] = $hours.'س';
        }

        if ($minutes > 0 || $parts === []) {
            $parts[] = $minutes.'د';
        }

        return implode(' ', $parts);
    }

    protected function parseMikrotikUptime(string $uptime): ?int
    {
        if ($uptime === '') {
            return null;
        }

        $seconds = 0;

        if (preg_match('/(\d+)w/', $uptime, $m)) {
            $seconds += (int) $m[1] * 604800;
        }

        if (preg_match('/(\d+)d/', $uptime, $m)) {
            $seconds += (int) $m[1] * 86400;
        }

        if (preg_match('/(\d+)h/', $uptime, $m)) {
            $seconds += (int) $m[1] * 3600;
        }

        if (preg_match('/(\d+)m/', $uptime, $m)) {
            $seconds += (int) $m[1] * 60;
        }

        if (preg_match('/(\d+)s/', $uptime, $m)) {
            $seconds += (int) $m[1];
        }

        return $seconds > 0 ? $seconds : null;
    }
}
