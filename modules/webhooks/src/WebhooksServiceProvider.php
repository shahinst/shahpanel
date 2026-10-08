<?php

namespace Modules\Webhooks;

use App\Models\Account;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Webhooks\Services\EventWebhookService;

/**
 * Service provider for the "webhooks" module: account lifecycle events posted
 * to an address of the admin's choice.
 *
 * The events come from the Account model itself, so every path that creates,
 * renews, expires or deletes an account (panel, bot, API, cron) is covered
 * without each of them having to remember to announce it.
 */
class WebhooksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'webhooks');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'webhooks');

        // Admin pages need the web group (session, CSRF, $errors).
        Route::middleware('web')->group(__DIR__.'/../routes/web.php');

        $events = fn (): EventWebhookService => $this->app->make(EventWebhookService::class);

        Account::created(fn (Account $account) => $events()->accountCreated($account));
        Account::updated(fn (Account $account) => $events()->accountUpdated($account));
        Account::deleted(fn (Account $account) => $events()->accountDeleted($account));
    }
}
