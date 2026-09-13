<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $defaults = [
            'portal_path_admin' => 'admin',
            'portal_path_agent' => 'agent',
            'portal_path_seller' => 'seller',
            'block_legacy_portal_paths' => '1',
        ];

        foreach ($defaults as $key => $value) {
            $exists = DB::table('settings')->where('key', $key)->exists();

            if (! $exists) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'portal_path_admin',
            'portal_path_agent',
            'portal_path_seller',
            'block_legacy_portal_paths',
        ])->delete();
    }
};
