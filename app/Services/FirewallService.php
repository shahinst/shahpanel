<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper over the root helper script.
 *
 * Every call is best-effort: the panel must keep working even when the
 * firewall cannot be reached, so failures are logged and reported, never
 * thrown into a request the user is waiting on.
 */
class FirewallService
{
    protected const SCRIPT = '/usr/local/sbin/panel-firewall';

    public function isAvailable(): bool
    {
        return is_file(self::SCRIPT);
    }

    public function init(): bool
    {
        return $this->run(['init'])['ok'];
    }

    /** Drop this address at the kernel for $seconds (0 = until flushed). */
    public function block(string $ip, int $seconds = 3600): bool
    {
        if (! $this->isRoutableIpv4($ip)) {
            return false;
        }

        return $this->run(['block', $ip, (string) max(0, $seconds)])['ok'];
    }

    public function unblock(string $ip): bool
    {
        if (! $this->isRoutableIpv4($ip)) {
            return false;
        }

        return $this->run(['unblock', $ip])['ok'];
    }

    /** @return list<string> */
    public function listBlocked(): array
    {
        $result = $this->run(['list']);

        if (! $result['ok']) {
            return [];
        }

        $rows = [];

        foreach (preg_split('/\R/', $result['output']) ?: [] as $line) {
            $ip = trim(explode(' ', trim($line))[0] ?? '');

            if ($ip !== '') {
                $rows[] = $ip;
            }
        }

        return $rows;
    }

    /** @return array{blocked: int, cn: int, chain: string, available: bool} */
    public function status(): array
    {
        $out = ['blocked' => 0, 'cn' => 0, 'chain' => 'unknown', 'available' => $this->isAvailable()];

        $result = $this->run(['status']);

        if (! $result['ok']) {
            return $out;
        }

        foreach (preg_split('/\R/', $result['output']) ?: [] as $line) {
            [$key, $value] = array_pad(explode('=', trim($line), 2), 2, null);

            if ($key === 'blocked' || $key === 'cn') {
                $out[$key] = (int) $value;
            } elseif ($key === 'chain') {
                $out['chain'] = (string) $value;
            }
        }

        return $out;
    }

    /** Ask root to refresh the country list on disk. */
    public function fetchLists(): bool
    {
        return $this->run(['fetch'], timeout: 200)['ok'];
    }

    /** Ask root to download and unpack the per-country zone files. */
    public function fetchZones(): bool
    {
        return $this->run(['zones-fetch'], timeout: 260)['ok'];
    }

    public function loadCountryBlock(): bool
    {
        return $this->run(['cn-load'], timeout: 180)['ok'];
    }

    public function clearCountryBlock(): bool
    {
        return $this->run(['cn-clear'])['ok'];
    }

    public function flush(): bool
    {
        return $this->run(['flush'])['ok'];
    }

    /**
     * Refuse anything that is not a plain, routable IPv4 address. The helper
     * validates too; this stops obvious nonsense before we shell out at all.
     */
    public function isRoutableIpv4(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * @param  list<string>  $args
     * @return array{ok: bool, output: string}
     */
    protected function run(array $args, int $timeout = 30): array
    {
        if (! $this->isAvailable()) {
            return ['ok' => false, 'output' => ''];
        }

        try {
            // The cwd is pinned to the app directory on purpose. Without it Symfony
            // inherits whatever directory the PHP process happens to sit in, and
            // refuses to start when www-data cannot read it -- which is exactly what
            // happens when the installer runs artisan from /root/sp: every firewall
            // call fails with a misleading "cwd does not exist".
            $process = new Process(array_merge(['sudo', '-n', self::SCRIPT], $args), base_path());
            $process->setTimeout($timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                Log::warning('panel-firewall failed', [
                    'args' => $args,
                    'exit' => $process->getExitCode(),
                    'stderr' => trim($process->getErrorOutput()),
                ]);

                return ['ok' => false, 'output' => trim($process->getErrorOutput())];
            }

            return ['ok' => true, 'output' => trim($process->getOutput())];
        } catch (\Throwable $e) {
            Log::warning('panel-firewall threw', ['args' => $args, 'error' => $e->getMessage()]);

            return ['ok' => false, 'output' => ''];
        }
    }
}
