<?php

namespace App\Services;

use App\Models\User;
use App\Support\CronDocumentation;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Detect hosting environment and install/monitor the panel crontab entry.
 */
class CronManagerService
{
    public const MARKER = 'shahpanel-schedule';

    /**
     * @return array{key: string, label: string, detail: string}
     */
    public function detectEnvironment(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                'key' => 'windows',
                'label' => 'Windows',
                'detail' => 'Task Scheduler — نصب خودکار crontab در این محیط پشتیبانی نمی‌شود.',
            ];
        }

        if ($this->isDirectAdmin()) {
            return [
                'key' => 'directadmin',
                'label' => 'DirectAdmin',
                'detail' => 'کران از طریق crontab کاربر (همان مسیر Linux) ثبت می‌شود.',
            ];
        }

        if (PHP_OS_FAMILY === 'Linux') {
            return [
                'key' => 'linux',
                'label' => 'Linux',
                'detail' => 'crontab کاربر PHP',
            ];
        }

        return [
            'key' => 'unknown',
            'label' => PHP_OS_FAMILY,
            'detail' => 'محیط ناشناخته — در صورت امکان crontab امتحان می‌شود.',
        ];
    }

    public function canInstall(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        return $this->shellAvailable();
    }

    public function installReason(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return __('automation.install_blocked_windows');
        }

        if (! $this->shellAvailable()) {
            return __('automation.install_blocked_exec');
        }

        return null;
    }

    /**
     * @return array{ok: bool, message: string, lines: list<string>}
     */
    public function installRequired(?User $actor = null): array
    {
        $lines = [];
        $reason = $this->installReason();

        if ($reason !== null) {
            return ['ok' => false, 'message' => $reason, 'lines' => [$reason]];
        }

        $required = CronDocumentation::requiredCrontabLines();
        $scheduleLine = $required[0] ?? null;

        if ($scheduleLine === null || $scheduleLine === '') {
            return ['ok' => false, 'message' => __('automation.no_required_line'), 'lines' => []];
        }

        if ($this->isPanelCronInstalled()) {
            $lines[] = __('automation.already_installed');

            return ['ok' => true, 'message' => __('automation.already_installed'), 'lines' => $lines];
        }

        $existing = $this->readCrontab();
        $lines[] = __('automation.env_detected', ['env' => $this->detectEnvironment()['label']]);

        $markedLine = $this->ensureMarker($scheduleLine);
        $merged = trim($existing."\n".$markedLine);
        $written = $this->writeCrontab($merged);

        if (! $written['ok']) {
            return [
                'ok' => false,
                'message' => $written['error'] ?? __('automation.install_failed'),
                'lines' => array_merge($lines, $written['lines'] ?? []),
            ];
        }

        $lines[] = __('automation.line_added', ['line' => $markedLine]);
        $lines[] = __('automation.verify_after_minute');

        Cache::put('system_health.cron_installed_at', now()->timestamp, now()->addDays(30));
        Cache::put('system_health.cron_installed_by', $actor?->id, now()->addDays(30));

        return ['ok' => true, 'message' => __('automation.install_success'), 'lines' => $lines];
    }

    public function isPanelCronInstalled(): bool
    {
        $crontab = $this->readCrontab();

        return str_contains($crontab, self::MARKER)
            || str_contains($crontab, 'artisan schedule:run');
    }

    public function readCrontabSafe(): string
    {
        $text = trim($this->readCrontab());

        return $text !== '' ? $text : __('automation.crontab_empty');
    }

    /**
     * @return list<string>
     */
    public function previewInstallLines(): array
    {
        return collect(CronDocumentation::requiredCrontabLines())
            ->map(fn (string $line): string => $this->ensureMarker($line))
            ->values()
            ->all();
    }

    protected function readCrontab(): string
    {
        if (! $this->shellAvailable()) {
            return '';
        }

        $output = [];
        $code = 1;
        @exec('crontab -l 2>/dev/null', $output, $code);

        if ($code !== 0 && $output === []) {
            return '';
        }

        return implode("\n", $output);
    }

    /**
     * @return array{ok: bool, error?: string, lines?: list<string>}
     */
    protected function writeCrontab(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shahpanel-cron-');

        if ($tmp === false) {
            return ['ok' => false, 'error' => __('automation.temp_file_failed')];
        }

        $lines = [];
        $normalized = rtrim(str_replace("\r\n", "\n", $content), "\n")."\n";

        try {
            if (file_put_contents($tmp, $normalized) === false) {
                return ['ok' => false, 'error' => __('automation.temp_write_failed')];
            }

            $out = [];
            $code = 1;
            @exec('crontab '.escapeshellarg($tmp).' 2>&1', $out, $code);
            $lines = $out;

            if ($code !== 0) {
                return [
                    'ok' => false,
                    'error' => trim(implode("\n", $out)) ?: __('automation.crontab_rejected'),
                    'lines' => $lines,
                ];
            }

            return ['ok' => true, 'lines' => $lines];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            @unlink($tmp);
        }
    }

    protected function ensureMarker(string $line): string
    {
        if (str_contains($line, self::MARKER)) {
            return $line;
        }

        return rtrim($line).' # '.self::MARKER;
    }

    protected function isDirectAdmin(): bool
    {
        return is_file('/usr/local/directadmin/directadmin')
            || is_dir('/usr/local/directadmin')
            || getenv('DDIRECTADMIN') !== false;
    }

    protected function shellAvailable(): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('exec', $disabled, true);
    }
}
