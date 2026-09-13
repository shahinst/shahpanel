<?php

namespace App\Providers;

use App\Services\Modules\ModuleManager;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleManager::class);

        // Boot every active module (registers its own service provider,
        // routes, views and migrations). Defensive against a not-yet-installed app.
        $this->app->make(ModuleManager::class)->registerActiveModules($this->app);
    }

    public function boot(): void
    {
        //
    }
}
