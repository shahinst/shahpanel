<?php

namespace App\Console\Commands;

use App\Support\PortalPaths;
use Illuminate\Console\Command;

/**
 * مسیرهای پنل در هر نصب تصادفی‌اند و بعداً هم از تنظیمات امنیتی قابل تغییرند،
 * پس هیچ فایل بیرونی‌ای نمی‌تواند آنها را حدس بزند. تنظیمات nginx و ModSecurity
 * لازم دارند بدانند مسیر واقعی ادمین چیست؛ این دستور همان چیزی را می‌گوید که
 * خود مسیریاب پنل استفاده می‌کند، با همان ترتیب اولویت (تنظیمات دیتابیس روی
 * مقدار .env). خواندن مستقیم .env جواب نمی‌دهد چون وقتی مسیر از داخل پنل عوض
 * شود در دیتابیس می‌نشیند و .env دست‌نخورده می‌ماند.
 */
class ShowPortalPathsCommand extends Command
{
    protected $signature = 'panel:paths
                            {--bare : فقط مسیرها، جدا با فاصله (برای اسکریپت‌ها)}
                            {--role= : فقط مسیر یک نقش (admin / agent / seller)}';

    protected $description = 'نمایش مسیرهای فعلی ورود ادمین، نماینده و فروشنده';

    public function handle(): int
    {
        $paths = PortalPaths::all();
        $role = $this->option('role');

        if ($role !== null && $role !== '') {
            if (! array_key_exists($role, $paths)) {
                $this->error('نقش ناشناخته: '.$role.' (admin / agent / seller)');

                return self::FAILURE;
            }

            $this->line($paths[$role]);

            return self::SUCCESS;
        }

        if ($this->option('bare')) {
            $this->line(implode(' ', array_values($paths)));

            return self::SUCCESS;
        }

        $this->table(
            ['نقش', 'مسیر'],
            array_map(
                static fn (string $roleKey, string $path): array => [$roleKey, '/'.$path],
                array_keys($paths),
                array_values($paths),
            ),
        );

        return self::SUCCESS;
    }
}
