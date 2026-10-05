<?php

namespace Modules\Dedicated;

use App\Support\PanelExtensions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Dedicated\Console\MeterCommand;
use Modules\Dedicated\Models\DedicatedServer;

/**
 * Dedicated agents and inbound resellers: agents who run on their own server
 * or on their own slice of one. Turning the module off removes both from the
 * panel; the data stays.
 */
class DedicatedServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'dedicated');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'dedicated');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::middleware('web')->group(__DIR__.'/../routes/web.php');

        // Only an agent who owns a dedicated server sees the entry.
        PanelExtensions::navItem(function (string $panel): ?array {
            if ($panel !== 'agent' || ! DedicatedServer::isDedicatedAgent(auth()->user())) {
                return null;
            }

            return [
                'label' => __('dedicated::admin.agent_menu'),
                'url' => route('agent.dedicated.index'),
                'icon' => 'bx-server',
                'active' => request()->routeIs('agent.dedicated.*'),
            ];
        });

        if ($this->app->runningInConsole()) {
            $this->commands([MeterCommand::class]);

            $this->app->booted(function (): void {
                $this->app->make(Schedule::class)->command('dedicated:meter')
                    ->everyFiveMinutes()->withoutOverlapping(10)->name('dedicated.meter');
            });
        }
    }
}
