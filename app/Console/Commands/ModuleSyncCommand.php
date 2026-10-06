<?php

namespace App\Console\Commands;

use App\Models\Module;
use App\Services\Modules\ModuleManager;
use Illuminate\Console\Command;

class ModuleSyncCommand extends Command
{
    protected $signature = 'module:sync';

    protected $description = 'Rebuild the active-modules cache (storage/app/modules_active.json) from the database and run active modules\' pending migrations';

    public function handle(ModuleManager $manager): int
    {
        $manager->refreshCache();
        $this->info('کش ماژول‌های فعال با موفقیت از دیتابیس بازسازی شد.');

        // update.sh migrates before this sync, while a stale cache can still
        // hide a module's provider and with it the module's new migrations.
        foreach (Module::query()->where('status', Module::STATUS_ACTIVE)->get() as $module) {
            $manager->migrate($module);
        }

        return self::SUCCESS;
    }
}
