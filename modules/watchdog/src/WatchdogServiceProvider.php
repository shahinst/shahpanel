<?php

namespace Modules\Watchdog;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the "watchdog" module: periodic checks of the servers
 * and the panel host, announced on the panel's Telegram backup bot.
 */
class WatchdogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'watchdog');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'watchdog');

        // Admin pages need the web group (session, CSRF, $errors).
        Route::middleware('web')->group(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([Console\CheckCommand::class]);
        }

        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command('watchdog:check')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground()->name('watchdog.check');
        });
    }
}
