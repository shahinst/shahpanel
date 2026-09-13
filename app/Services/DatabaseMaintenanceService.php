<?php

namespace App\Services;

use App\Models\MaintenanceLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class DatabaseMaintenanceService
{
    /**
     * Tables required by the panel (including newer VPN features).
     *
     * @return list<string>
     */
    public function requiredTables(): array
    {
        return [
            'users',
            'wallets',
            'servers',
            'server_interfaces',
            'server_sync_logs',
            'packages',
            'accounts',
            'account_usage_logs',
            'settings',
            'maintenance_logs',
            'account_server_changes',
            'notification_broadcasts',
            'package_durations',
            'package_server',
            'wallet_adjustments',
            'server_backups',
        ];
    }

    /**
     * @return array{status: string, lines: list<string>, missing_before: list<string>, missing_after: list<string>, output: string}
     */
    public function runMigrations(?User $user = null): array
    {
        $missingBefore = $this->missingTables();
        $lines = [];

        if ($missingBefore !== []) {
            $lines[] = 'جداول موجود نبود: '.implode(', ', $missingBefore);
        } else {
            $lines[] = 'همه جداول اصلی قبل از migrate موجود بودند.';
        }

        Artisan::call('migrate', ['--force' => true]);
        $output = trim(Artisan::output());

        if ($output !== '') {
            $lines[] = $output;
        } else {
            $lines[] = 'migrate اجرا شد (بدون خروجی اضافه).';
        }

        $missingAfter = $this->missingTables();

        if ($missingAfter === []) {
            $lines[] = 'همه جداول مورد نیاز اکنون موجودند.';
            $status = 'success';
        } else {
            $lines[] = 'هنوز جداول زیر موجود نیستند: '.implode(', ', $missingAfter);
            $status = 'partial';
        }

        $this->logAction($user, 'migrate', $status, [
            'missing_before' => $missingBefore,
            'missing_after' => $missingAfter,
        ], $output);

        return [
            'status' => $status,
            'lines' => $lines,
            'missing_before' => $missingBefore,
            'missing_after' => $missingAfter,
            'output' => $output,
        ];
    }

    /**
     * @return array{status: string, lines: list<string>, missing: list<string>}
     */
    public function verifySchema(?User $user = null): array
    {
        $missing = $this->missingTables();
        $lines = [];

        if ($missing === []) {
            $lines[] = 'ساختار دیتابیس کامل است.';
            $status = 'success';
        } else {
            $lines[] = 'جداول زیر یافت نشدند: '.implode(', ', $missing);
            $lines[] = 'دکمه «اجرای migrate» را بزنید تا جداول جدید ساخته شوند.';
            $status = 'failed';
        }

        $this->logAction($user, 'verify_schema', $status, ['missing' => $missing]);

        return [
            'status' => $status,
            'lines' => $lines,
            'missing' => $missing,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    protected function logAction(
        ?User $user,
        string $action,
        string $status,
        ?array $details = null,
        ?string $output = null
    ): void {
        if (! Schema::hasTable('maintenance_logs')) {
            return;
        }

        MaintenanceLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'status' => $status,
            'details' => $details,
            'output' => $output,
            'ran_at' => now(),
        ]);
    }

    /**
     * @return list<string>
     */
    public function missingTables(): array
    {
        $missing = [];

        foreach ($this->requiredTables() as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * @return list<array{
     *     required: bool,
     *     label: string,
     *     cron: string,
     *     internal: string,
     *     description: string,
     *     crontab: ?string
     * }>
     */
    public function cronJobs(): array
    {
        return \App\Support\CronDocumentation::entries();
    }
}
