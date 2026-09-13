<?php

namespace App\Console\Commands;

use App\Services\Modules\ModuleManager;
use Illuminate\Console\Command;

class ModuleSyncCommand extends Command
{
    protected $signature = 'module:sync';

    protected $description = 'Rebuild the active-modules cache (storage/app/modules_active.json) from the database';

    public function handle(ModuleManager $manager): int
    {
        $manager->refreshCache();
        $this->info('کش ماژول‌های فعال با موفقیت از دیتابیس بازسازی شد.');

        return self::SUCCESS;
    }
}
