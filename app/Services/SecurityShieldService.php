<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper over /usr/local/sbin/panel-security-shield.
 *
 * CrowdSec IP bans + ClamAV scans + ModSecurity upload/malware guard.
 * Panel account POSTs keep CRS SQLi/XSS exclusions so create/delete works.
 */
class SecurityShieldService
{
    protected const SCRIPT = '/usr/local/sbin/panel-security-shield';

    public function isAvailable(): bool
    {
        return is_file(self::SCRIPT);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $result = $this->runJson(['status']);

        if (! ($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'available' => $this->isAvailable(),
                'crowdsec' => 'down',
                'fail2ban' => 'down',
                'clamav' => 'down',
                'nginx' => 'unknown',
                'bouncer' => 'down',
                'modsecurity' => 'down',
                'decisions' => 0,
                'panel_bans' => 0,
                'whitelist' => 0,
                'infected' => 0,
                'danger_events' => 0,
                'scanning' => false,
                'body_waf' => 'upload-only',
                'egress_safe' => true,
                'mode' => 'unavailable',
            ];
        }

        $result['available'] = true;

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function decisions(): array
    {
        $result = $this->runJson(['decisions']);

        return is_array($result['items'] ?? null) ? $result['items'] : [];
    }

    public function ban(string $ip, int $seconds = 3600): bool
    {
        if (! $this->isValidIpv4($ip)) {
            return false;
        }

        return (bool) ($this->runJson(['ban', $ip, (string) max(60, $seconds)])['ok'] ?? false);
    }

    public function unban(string $ip): bool
    {
        if (! $this->isValidIpv4($ip)) {
            return false;
        }

        return (bool) ($this->runJson(['unban', $ip])['ok'] ?? false);
    }

    /** @return list<string> */
    public function whitelist(): array
    {
        $result = $this->runJson(['whitelist-list']);

        return is_array($result['items'] ?? null) ? array_values(array_map('strval', $result['items'])) : [];
    }

    public function whitelistAdd(string $ip): bool
    {
        if (! $this->isValidIpv4($ip)) {
            return false;
        }

        return (bool) ($this->runJson(['whitelist-add', $ip])['ok'] ?? false);
    }

    public function whitelistDel(string $ip): bool
    {
        if (! $this->isValidIpv4($ip)) {
            return false;
        }

        return (bool) ($this->runJson(['whitelist-del', $ip])['ok'] ?? false);
    }

    /**
     * Push a full allowlist (e.g. from IpWhitelist model) to CrowdSec.
     *
     * @param  list<string>  $ips
     */
    public function whitelistSync(array $ips): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        $filtered = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if ($this->isValidIpv4($ip)) {
                $filtered[$ip] = true;
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'shield-wl-');
        if ($tmp === false) {
            return false;
        }

        try {
            file_put_contents($tmp, implode("\n", array_keys($filtered))."\n");
            $result = $this->runJson(['whitelist-sync', $tmp], timeout: 60);

            return (bool) ($result['ok'] ?? false);
        } finally {
            @unlink($tmp);
        }
    }

    /** @return array{ok: bool, file?: string, lines: list<string>} */
    public function logs(string $kind = 'crowdsec'): array
    {
        $allowed = ['crowdsec', 'nginx', 'nginx-error', 'fail2ban', 'clamav', 'modsec', 'modsec-danger', 'audit'];
        if (! in_array($kind, $allowed, true)) {
            $kind = 'crowdsec';
        }

        $result = $this->runJson(['logs', $kind]);

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'file' => (string) ($result['file'] ?? ''),
            'lines' => is_array($result['lines'] ?? null) ? $result['lines'] : [],
        ];
    }

    public function startScan(?string $path = null): bool
    {
        $args = ['scan-start'];
        if ($path !== null && $path !== '') {
            $args[] = $path;
        }

        return (bool) ($this->runJson($args, timeout: 30)['ok'] ?? false);
    }

    /** @return array<string, mixed> */
    public function scanReport(): array
    {
        return $this->runJson(['scan-report']);
    }

    /** @return list<array<string, mixed>> */
    public function alerts(): array
    {
        $result = $this->runJson(['alerts']);

        return is_array($result['items'] ?? null) ? $result['items'] : [];
    }

    public function serviceAction(string $action, string $service): bool
    {
        $allowedServices = [
            'crowdsec',
            'fail2ban',
            'clamav-daemon',
            'clamav-freshclam',
            'crowdsec-firewall-bouncer',
            'nginx',
        ];
        $allowedActions = ['start', 'stop', 'restart', 'reload'];

        if (! in_array($service, $allowedServices, true) || ! in_array($action, $allowedActions, true)) {
            return false;
        }

        if ($service === 'nginx' && $action === 'stop') {
            return false;
        }

        return (bool) ($this->runJson(['services', $action, $service], timeout: 60)['ok'] ?? false);
    }

    public function init(): bool
    {
        return (bool) ($this->runJson(['init'], timeout: 60)['ok'] ?? false);
    }

    public function isValidIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    protected function runJson(array $args, int $timeout = 45): array
    {
        $result = $this->run($args, $timeout);

        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['output']];
        }

        $decoded = json_decode($result['output'], true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'invalid json'];
    }

    /**
     * @param  list<string>  $args
     * @return array{ok: bool, output: string}
     */
    protected function run(array $args, int $timeout = 45): array
    {
        if (! $this->isAvailable()) {
            return ['ok' => false, 'output' => 'helper missing'];
        }

        try {
            $process = new Process(array_merge(['sudo', '-n', self::SCRIPT], $args));
            $process->setTimeout($timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                Log::warning('panel-security-shield failed', [
                    'args' => $args,
                    'exit' => $process->getExitCode(),
                    'stderr' => trim($process->getErrorOutput()),
                ]);

                return ['ok' => false, 'output' => trim($process->getErrorOutput() ?: $process->getOutput())];
            }

            return ['ok' => true, 'output' => trim($process->getOutput())];
        } catch (\Throwable $e) {
            Log::warning('panel-security-shield threw', ['args' => $args, 'error' => $e->getMessage()]);

            return ['ok' => false, 'output' => $e->getMessage()];
        }
    }
}
