<?php

namespace App\Support;

/**
 * Single source of truth for crontab lines documented in admin maintenance UI.
 * Keep in sync with routes/console.php Schedule definitions.
 */
class CronDocumentation
{
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
    public static function entries(?string $projectPath = null): array
    {
        $path = $projectPath ?? str_replace('\\', '/', base_path());

        return [
            [
                'required' => true,
                'label' => __('backend.cron_schedule_label'),
                'cron' => '* * * * *',
                'internal' => 'php artisan schedule:run',
                'description' => __('backend.cron_schedule_description'),
                'crontab' => "* * * * * cd {$path} && php artisan schedule:run >> {$path}/storage/logs/scheduler.log 2>&1 # shahpanel-schedule",
            ],
            [
                'required' => true,
                'label' => __('backend.cron_queue_label'),
                'cron' => '* * * * *',
                'internal' => 'php artisan queue:work --stop-when-empty --max-time=55',
                'description' => __('backend.cron_queue_description'),
                'crontab' => "* * * * * cd {$path} && php artisan queue:work --queue=tunneling,default --stop-when-empty --max-time=55 >> {$path}/storage/logs/queue.log 2>&1 # shahpanel-queue",
            ],
            [
                'required' => false,
                'label' => __('backend.cron_sync_usage_label'),
                'cron' => '*/5 * * * *',
                'internal' => 'php artisan sync:usage',
                'description' => __('backend.cron_sync_usage_description'),
                'crontab' => null,
            ],
            [
                'required' => false,
                'label' => __('backend.cron_check_expiry_label'),
                'cron' => '*/10 * * * *',
                'internal' => 'php artisan accounts:check-expiry',
                'description' => __('backend.cron_check_expiry_description'),
                'crontab' => null,
            ],
            [
                'required' => false,
                'label' => __('backend.cron_alerts_label'),
                'cron' => '0 * * * *',
                'internal' => 'php artisan alerts:dispatch',
                'description' => __('backend.cron_alerts_description'),
                'crontab' => null,
            ],
            [
                'required' => false,
                'label' => __('backend.cron_backup_label'),
                'cron' => '0 2 * * *',
                'internal' => 'php artisan backup:database',
                'description' => __('backend.cron_backup_description'),
                'crontab' => null,
            ],
            [
                'required' => false,
                'label' => __('backend.cron_daily_report_label'),
                'cron' => '0 0 * * *',
                'internal' => 'php artisan reports:daily-rollup',
                'description' => __('backend.cron_daily_report_description'),
                'crontab' => null,
            ],
            [
                'required' => false,
                'label' => __('backend.cron_auto_close_tickets_label'),
                'cron' => '0 0 * * *',
                'internal' => 'php artisan tickets:auto-close',
                'description' => __('backend.cron_auto_close_tickets_description'),
                'crontab' => null,
            ],
        ];
    }

    /**
     * Lines to paste into server crontab (required entries only).
     *
     * @return list<string>
     */
    public static function requiredCrontabLines(?string $projectPath = null): array
    {
        return collect(self::entries($projectPath))
            ->filter(fn (array $row): bool => $row['required'] && $row['crontab'] !== null)
            ->pluck('crontab')
            ->values()
            ->all();
    }
}
