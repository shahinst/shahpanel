<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lists the dedicated-agents module on the modules page. A panel that already
 * runs inbound resellers gets it switched on, because those pages moved into
 * the module and would otherwise vanish with the update.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $manifestPath = base_path('modules/dedicated/module.json');

        if (! is_file($manifestPath)) {
            return;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            return;
        }

        $now = now();
        $inUse = Schema::hasTable('inbound_allocations') && DB::table('inbound_allocations')->exists();
        $existing = DB::table('modules')->where('slug', 'dedicated')->first();

        if ($existing !== null) {
            DB::table('modules')->where('slug', 'dedicated')->update([
                'name' => $manifest['name'] ?? 'Dedicated agents',
                'version' => $manifest['version'] ?? '1.0.0',
                'description' => $manifest['description'] ?? null,
                'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('modules')->insert([
            'slug' => 'dedicated',
            'name' => $manifest['name'] ?? 'Dedicated agents',
            'version' => $manifest['version'] ?? '1.0.0',
            'description' => $manifest['description'] ?? null,
            'author' => $manifest['author'] ?? null,
            'provider' => $manifest['provider'] ?? null,
            'status' => $inUse ? 'active' : 'installed',
            'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE),
            'installed_at' => $now,
            'activated_at' => $inUse ? $now : null,
            'updated_at' => $now,
            'created_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('slug', 'dedicated')->where('status', '!=', 'active')->delete();
        }
    }
};
