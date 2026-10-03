<?php

namespace Modules\TgTunnel;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TgTunnelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Support\Tunnel::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views/tgtunnel', 'tgtunnel');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'tgtunnel');

        // Admin pages need the web group (session, CSRF, $errors).
        Route::middleware('web')->group(__DIR__.'/../routes/web.php');
    }
}
