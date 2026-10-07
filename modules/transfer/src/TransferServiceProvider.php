<?php

namespace Modules\Transfer;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the "transfer" module: moving a whole panel to a new
 * server by uploading one database backup into a freshly installed panel.
 */
class TransferServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'transfer');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'transfer');

        // Progress is read while the panel is down and its database is being
        // replaced, so it gets no session and no database; the job id is the
        // secret.
        Route::middleware('throttle:120,1')->group(__DIR__.'/../routes/status.php');

        // Admin pages need the web group (session, CSRF, $errors).
        Route::middleware('web')->group(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([Console\RestoreCommand::class]);
        }
    }
}
