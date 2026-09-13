<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Sanaei → Remnawave transfer tool moved out of the core into
 * modules/migrate.
 *
 * Its routes and pages now only exist while that module is active, so every
 * panel that already had the tool has to be registered as an active module
 * here — otherwise updating would silently remove a working feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $manifestPath = base_path('modules/migrate/module.json');

        if (! is_file($manifestPath)) {
            return;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            return;
        }

        $now = now();

        DB::table('modules')->updateOrInsert(
            ['slug' => 'migrate'],
            [
                'name' => $manifest['name'] ?? 'Migrate',
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
            DB::table('modules')->where('slug', 'migrate')->delete();
        }
    }
};
