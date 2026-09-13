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
                'label' => 'اجرای زمان‌بند Laravel',
                'cron' => '* * * * *',
                'internal' => 'php artisan schedule:run',
                'description' => 'الزامی — هر دقیقه یک‌بار؛ همه jobهای زیر از این دستور اجرا می‌شوند.',
                'crontab' => "* * * * * cd {$path} && php artisan schedule:run >> {$path}/storage/logs/scheduler.log 2>&1 # vpnpanel-schedule",
            ],
            [
                'required' => true,
                'label' => 'پردازش صف (Queue Worker)',
                'cron' => '* * * * *',
                'internal' => 'php artisan queue:work --stop-when-empty --max-time=55',
                'description' => 'الزامی برای سیستم تونلینگ جدید — هر دقیقه صف database را پردازش می‌کند (apply / reconcile / متریک‌ها) و بعد از ۵۵ ثانیه یا خالی‌شدن صف خارج می‌شود.',
                'crontab' => "* * * * * cd {$path} && php artisan queue:work --queue=tunneling,default --stop-when-empty --max-time=55 >> {$path}/storage/logs/queue.log 2>&1 # vpnpanel-queue",
            ],
            [
                'required' => false,
                'label' => 'سینک حجم مصرفی VPN',
                'cron' => '*/5 * * * *',
                'internal' => 'php artisan sync:usage',
                'description' => 'خواندن مصرف از سرورها → دیتابیس؛ در صورت اتمام حجم، اکانت غیرفعال می‌شود.',
                'crontab' => null,
            ],
            [
                'required' => false,
                'label' => 'بررسی انقضای اکانت‌ها',
                'cron' => '*/10 * * * *',
                'internal' => 'php artisan accounts:check-expiry',
                'description' => 'غیرفعال‌سازی اکانت‌های منقضی بر اساس expiry_at در دیتابیس.',
                'crontab' => null,
            ],
            [
                'required' => false,
                'label' => 'هشدارها (ایمیل/اعلان)',
                'cron' => '0 * * * *',
                'internal' => 'php artisan alerts:dispatch',
                'description' => 'نزدیک شدن به انقضا، ۹۰٪ حجم، خطای sync.',
                'crontab' => null,
            ],
            [
                'required' => false,
                'label' => 'پشتیبان‌گیری دیتابیس',
                'cron' => '0 2 * * *',
                'internal' => 'php artisan backup:database',
                'description' => 'هر شب ساعت ۰۲:۰۰.',
                'crontab' => null,
            ],
            [
                'required' => false,
                'label' => 'گزارش روزانه',
                'cron' => '0 0 * * *',
                'internal' => 'php artisan reports:daily-rollup',
                'description' => 'جمع‌بندی آمار روزانه نیمه‌شب.',
                'crontab' => null,
            ],
            [
                'required' => false,
                'label' => 'بستن خودکار تیکت‌ها',
                'cron' => '0 0 * * *',
                'internal' => 'php artisan tickets:auto-close',
                'description' => 'روزانه.',
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
