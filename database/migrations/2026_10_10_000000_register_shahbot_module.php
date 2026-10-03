<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lists the bundled Telegram sales bot module on the modules page. It is
 * registered as installed, not active: the owner turns it on from Settings >
 * Modules, which also creates its tables. A panel that already activated it
 * keeps its state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $manifestPath = base_path('modules/shahbot/module.json');

        if (! is_file($manifestPath)) {
            return;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            return;
        }

        $now = now();
        $existing = DB::table('modules')->where('slug', 'shahbot')->first();

        if ($existing !== null) {
            DB::table('modules')->where('slug', 'shahbot')->update([
                'name' => $manifest['name'] ?? 'ShahBot',
                'version' => $manifest['version'] ?? '1.0.0',
                'description' => $manifest['description'] ?? null,
                'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('modules')->insert([
            'slug' => 'shahbot',
            'name' => $manifest['name'] ?? 'ShahBot',
            'version' => $manifest['version'] ?? '1.0.0',
            'description' => $manifest['description'] ?? null,
            'author' => $manifest['author'] ?? null,
            'provider' => $manifest['provider'] ?? null,
            'status' => 'installed',
            'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE),
            'installed_at' => $now,
            'activated_at' => null,
            'updated_at' => $now,
            'created_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('slug', 'shahbot')->where('status', '!=', 'active')->delete();
        }
    }
};
