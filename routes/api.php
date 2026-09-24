<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\ResellerController;
use App\Http\Controllers\Api\V1\StatsController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

/*
 * Reseller API v1 — the surface a Telegram bot talks to.
 *
 * Auth is a bearer token from POST /api/v1/auth/login. Every route below the
 * `api.auth` middleware is scoped to the token owner's own hierarchy.
 */

Route::prefix('v1')->group(function (): void {
    // Public: credentials in, token out.
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('api.throttle:10');

    // Stays open — it is a liveness probe — but not free: without a limit the
    // same IP could hammer it forever. `throttle:api` is the panel's existing
    // named limiter (60/min, keyed by IP for a guest), so this adds no new
    // mechanism and shares no bucket with the login or client-panel limiters.
    Route::get('ping', fn () => response()->json([
        'ok' => true,
        'data' => ['pong' => true, 'time' => now()->toIso8601String()],
    ]))->middleware('throttle:api');

    Route::middleware(['api.auth', 'api.throttle'])->group(function (): void {
        // ── Identity ─────────────────────────────────────────────────────
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/tokens', [AuthController::class, 'tokens']);
        Route::post('auth/tokens', [AuthController::class, 'createToken']);
        Route::delete('auth/tokens/{token}', [AuthController::class, 'revokeToken']);

        // ── Catalog ──────────────────────────────────────────────────────
        Route::middleware('api.ability:catalog:read')->group(function (): void {
            Route::get('catalog/packages', [CatalogController::class, 'packages']);
            Route::get('catalog/servers', [CatalogController::class, 'servers']);
            Route::get('catalog/price', [CatalogController::class, 'price']);
        });

        // ── Accounts ─────────────────────────────────────────────────────
        Route::middleware('api.ability:accounts:read')->group(function (): void {
            Route::get('accounts', [AccountController::class, 'index']);
            Route::get('accounts/preview', [AccountController::class, 'preview']);
            Route::get('accounts/{accountKey}', [AccountController::class, 'show']);
            Route::get('accounts/{accountKey}/config', [AccountController::class, 'config']);
            Route::get('accounts/{accountKey}/usage', [AccountController::class, 'usage']);
        });

        Route::post('accounts', [AccountController::class, 'store'])
            ->middleware('api.ability:accounts:create');

        Route::post('accounts/{accountKey}/renew', [AccountController::class, 'renew'])
            ->middleware('api.ability:accounts:renew');

        Route::middleware('api.ability:accounts:update')->group(function (): void {
            Route::post('accounts/{accountKey}/enable', [AccountController::class, 'enable']);
            Route::post('accounts/{accountKey}/disable', [AccountController::class, 'disable']);
        });

        // ── Wallet ───────────────────────────────────────────────────────
        Route::middleware('api.ability:wallet:read')->group(function (): void {
            Route::get('wallet', [WalletController::class, 'show']);
            Route::get('wallet/transactions', [WalletController::class, 'transactions']);
        });

        // ── Stats ────────────────────────────────────────────────────────
        Route::get('stats/dashboard', [StatsController::class, 'dashboard'])
            ->middleware('api.ability:stats:read');

        // ── Sub-resellers (agents only) ──────────────────────────────────
        Route::middleware(['api.role:agent', 'api.ability:resellers:read'])->group(function (): void {
            Route::get('resellers', [ResellerController::class, 'index']);
            Route::get('resellers/{reseller}', [ResellerController::class, 'show']);
            Route::get('resellers/{reseller}/accounts', [ResellerController::class, 'accounts']);
        });
    });
});

/*
 * نمای سازگار با مرزبان — سطحی کاملاً جدا و موازی با v1.
 *
 * مسیرهایش در خودِ درایور ربات‌های میرزا و ویزویز ثابت‌اند (api/admin/token،
 * api/user، api/users، ...)، پس بیرون از گروه v1 و با همان پیشوند api ثبت
 * می‌شود. قبل از fallback می‌آید تا حتی اگر رفتار fallback لاراول عوض شود،
 * ترتیب ثبت درست بماند.
 */
require __DIR__.'/marzban.php';

// A mistyped path must still answer in JSON — an HTML error page would break
// the bot's parser instead of telling it what went wrong.
Route::fallback([MetaController::class, 'fallback']);
