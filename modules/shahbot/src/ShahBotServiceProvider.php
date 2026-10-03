<?php

namespace Modules\ShahBot;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\ShahBot\Support\BotSettings;

/**
 * The Telegram sales bot module. While active it owns the webhook, the bot's
 * admin section in the panel, its console commands and its schedule (long
 * polling, broadcast batches, expiry reminders). Deactivating the module
 * removes all of it; the tables and their data stay for a later reactivation.
 */
class ShahBotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BotSettings::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views/shahbot', 'shahbot');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'shahbot');

        // Activation runs these once; registering them also lets a panel update
        // (`php artisan migrate`) apply later schema changes of an active bot.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // The webhook carries its own secret and gets no session or CSRF.
        Route::middleware('throttle:5000,1')->group(__DIR__.'/../routes/webhook.php');

        // Admin pages need the web group (session, CSRF, $errors).
        Route::middleware('web')->group(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\PollCommand::class,
                Console\BroadcastCommand::class,
                Console\RemindCommand::class,
            ]);
        }

        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('shahbot:poll')->everyMinute()->withoutOverlapping(2)->runInBackground()->name('shahbot.poll');
            $schedule->command('shahbot:broadcast')->everyMinute()->withoutOverlapping(10)->runInBackground()->name('shahbot.broadcast');
            $schedule->command('shahbot:remind')->hourlyAt(17)->withoutOverlapping()->name('shahbot.remind');
        });
    }
}
