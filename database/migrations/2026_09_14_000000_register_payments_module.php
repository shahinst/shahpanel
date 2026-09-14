<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The payment section (gateways, wallet top-up, the NowPayments driver and the
 * payment webhooks) moved out of the core into modules/payments.
 *
 * Unlike the tunneling and migrate modules, no migration ever registered it, so
 * `module_active('payments')` returned false on every install. That silently
 * disabled the whole section: the gateway top-up menu and the admin payment
 * settings menus were hidden, GatewayTopUpController, Admin\GatewayPaymentController
 * and Admin\PaymentGatewayController answered 404 through the `module:payments`
 * middleware, and the NowPayments webhook had no route at all.
 *
 * Registering it as active restores the behaviour every panel had before the
 * extraction. An admin who genuinely does not want it can deactivate it from
 * the modules page afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $manifestPath = base_path('modules/payments/module.json');

        if (! is_file($manifestPath)) {
            return;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            return;
        }

        $now = now();

        DB::table('modules')->updateOrInsert(
            ['slug' => 'payments'],
            [
                'name' => $manifest['name'] ?? 'Payments',
                'version' => $manifest['version'] ?? '1.0.0',
                'description' => $manifest['description'] ?? null,
                'author' => $manifest['author'] ?? null,
                'provider' => $manifest['provider'] ?? null,
                'status' => 'active',
                'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE),
                'installed_at' => $now,
                'activated_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        // The router reads the JSON cache, not the table, so it has to be
        // rebuilt before the next request or the routes stay missing.
        try {
            app(\App\Services\Modules\ModuleManager::class)->refreshCache();
        } catch (\Throwable) {
            // A stale cache is repaired by `php artisan module:sync`.
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('slug', 'payments')->delete();
        }
    }
};
