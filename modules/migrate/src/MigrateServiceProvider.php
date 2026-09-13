<?php

namespace Modules\Migrate;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the "migrate" module — the Sanaei → Remnawave transfer
 * tool. While active it owns that admin section (its pages, routes and views);
 * deactivating the module removes the menu entry and the pages.
 *
 * The migration services and the ServerMigration models stay in the core: the
 * server-to-server Sanaei move in ServerOperationsController uses the same
 * services, and the migrations that own those tables must run either way.
 */
class MigrateServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views/migrate', 'migrate');

        // withRouting(web: ...) applies the "web" group for the core route file;
        // module routes are registered separately and get nothing by default, so
        // without this the pages render with no session, no CSRF and no shared
        // $errors bag.
        Route::middleware('web')->group(__DIR__.'/../routes/web.php');
    }
}
