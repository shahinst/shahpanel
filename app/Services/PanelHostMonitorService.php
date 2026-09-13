<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class PanelHostMonitorService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        try {
            return Cache::remember('panel-host-monitor', now()->addSeconds(5), function (): array {
                return $this->collect();
            });
        } catch (\Throwable $exception) {
            report($exception);

            return array_merge($this->collectFallbackBase(), [
                'health' => 'warning',
                'error' => $exception->getMessage(),
                'disk' => $this->diskUsage(base_path()),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function collectFallbackBase(): array
    {
        return [
            'hostname' => (string) gethostname(),
            'php_version' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'checked_at' => now()->toIso8601String(),
            'cpu_percent' => null,
            'cpu_cores' => max(1, (int) ($GLOBALS['_SERVER']['NUMBER_OF_PROCESSORS'] ?? 1)),
            'load' => [],
            'memory' => null,
            'disk' => null,
            'uptime_seconds' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function collect(): array
    {
        $base = [
            'hostname' => (string) gethostname(),
            'php_version' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'checked_at' => now()->toIso8601String(),
            'cpu_percent' => null,
            'cpu_cores' => $this->cpuCores(),
            'load' => [],
            'memory' => null,
            'disk' => null,
            'uptime_seconds' => null,
            'health' => 'unknown',
            'error' => null,
        ];

        try {
            if (PHP_OS_FAMILY === 'Linux') {
                return array_merge($base, $this->collectLinux());
            }

            if (PHP_OS_FAMILY === 'Windows') {
                return array_merge($base, $this->collectWindows());
            }

            return array_merge($base, [
                'disk' => $this->diskUsage(base_path()),
                'health' => 'healthy',
            ]);
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'health' => 'warning',
                'error' => $exception->getMessage(),
                'disk' => $this->diskUsage(base_path()),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function collectLinux(): array
    {
        $memory = $this->linuxMemory();
        $disk = $this->diskUsage(base_path());
        $cpuPercent = $this->linuxCpuPercent();
        $load = sys_getloadavg() ?: [];
        $loadFormatted = array_map(
            fn (float $value): string => number_format($value, 2, '.', ''),
            array_slice($load, 0, 3)
        );

        return [
            'cpu_percent' => $cpuPercent,
            'load' => $loadFormatted,
            'memory' => $memory,
            'disk' => $disk,
            'uptime_seconds' => $this->linuxUptimeSeconds(),
            'health' => $this->healthFromUsage($cpuPercent, $memory['percent'] ?? null, $disk['percent'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function collectWindows(): array
    {
        $disk = $this->diskUsage(base_path());
        $memory = $this->windowsMemory();

        return [
            'cpu_percent' => null,
            'load' => [],
            'memory' => $memory,
            'disk' => $disk,
            'health' => $this->healthFromUsage(null, $memory['percent'] ?? null, $disk['percent'] ?? null),
        ];
    }

    protected function linuxCpuPercent(): ?float
    {
        $first = $this->readProcStat();

        if ($first === null) {
            return null;
        }

        usleep(300_000);
        $second = $this->readProcStat();

        if ($second === null) {
            return null;
        }

        $idle = max(0, $second['idle'] - $first['idle']);
        $total = max(1, $second['total'] - $first['total']);
        $used = max(0, $total - $idle);

        return round(($used / $total) * 100, 1);
    }

    /**
     * @return array{idle: int, total: int}|null
     */
    protected function readProcStat(): ?array
    {
        $lines = @file('/proc/stat', FILE_IGNORE_NEW_LINES);

        if ($lines === false || ! isset($lines[0])) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($lines[0]));

        if ($parts === false || ($parts[0] ?? '') !== 'cpu') {
            return null;
        }

        $values = array_map('intval', array_slice($parts, 1));

        return [
            'idle' => ($values[3] ?? 0) + ($values[4] ?? 0),
            'total' => array_sum($values),
        ];
    }

    /**
     * @return array{used_bytes: int, total_bytes: int, percent: float, used_label: string, total_label: string}|null
     */
    protected function linuxMemory(): ?array
    {
        $raw = @file_get_contents('/proc/meminfo');

        if ($raw === false) {
            return null;
        }

        $info = [];
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s+kB$/', trim($line), $matches) === 1) {
                $info[$matches[1]] = (int) $matches[2] * 1024;
            }
        }

        $total = (int) ($info['MemTotal'] ?? 0);
        $available = (int) ($info['MemAvailable'] ?? ($info['MemFree'] ?? 0));
        $used = max(0, $total - $available);

        return $this->usageBlock($used, $total);
    }

    /**
     * @return array{used_bytes: int, total_bytes: int, percent: float, used_label: string, total_label: string}|null
     */
    protected function windowsMemory(): ?array
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $output = shell_exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /Value');

        if (! is_string($output) || $output === '') {
            return null;
        }

        $freeKb = 0;
        $totalKb = 0;

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/FreePhysicalMemory=(\d+)/', $line, $m) === 1) {
                $freeKb = (int) $m[1];
            }
            if (preg_match('/TotalVisibleMemorySize=(\d+)/', $line, $m) === 1) {
                $totalKb = (int) $m[1];
            }
        }

        if ($totalKb <= 0) {
            return null;
        }

        $total = $totalKb * 1024;
        $used = max(0, $total - ($freeKb * 1024));

        return $this->usageBlock($used, $total);
    }

    protected function linuxUptimeSeconds(): ?int
    {
        $uptime = @file_get_contents('/proc/uptime');

        if ($uptime === false) {
            return null;
        }

        $seconds = (float) trim(explode(' ', $uptime)[0] ?? '0');

        return $seconds > 0 ? (int) round($seconds) : null;
    }

    /**
     * @return array{used_bytes: int, total_bytes: int, percent: float, used_label: string, total_label: string}|null
     */
    protected function diskUsage(string $path): ?array
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $free === false || $total <= 0) {
            return null;
        }

        $used = max(0, (int) $total - (int) $free);

        return $this->usageBlock($used, (int) $total);
    }

    /**
     * @return array{used_bytes: int, total_bytes: int, percent: float, used_label: string, total_label: string}
     */
    protected function usageBlock(int $used, int $total): array
    {
        $percent = $total > 0 ? round(($used / $total) * 100, 1) : 0.0;

        return [
            'used_bytes' => $used,
            'total_bytes' => $total,
            'percent' => $percent,
            'used_label' => format_data_size($used),
            'total_label' => format_data_size($total),
        ];
    }

    protected function cpuCores(): int
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $count = @file_get_contents('/proc/cpuinfo');
            if (is_string($count)) {
                preg_match_all('/^processor\s*:/m', $count, $matches);

                return max(1, count($matches[0] ?? []));
            }
        }

        return max(1, (int) ($GLOBALS['_SERVER']['NUMBER_OF_PROCESSORS'] ?? 1));
    }

    protected function healthFromUsage(?float $cpu, ?float $memory, ?float $disk): string
    {
        $metrics = array_filter([$cpu, $memory, $disk], fn ($value): bool => $value !== null);

        if ($metrics === []) {
            return 'healthy';
        }

        $max = max($metrics);

        if ($max >= 90) {
            return 'critical';
        }

        if ($max >= 75) {
            return 'warning';
        }

        return 'healthy';
    }
}
