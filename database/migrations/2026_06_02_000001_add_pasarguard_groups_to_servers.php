<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'pasarguard_groups')) {
                $table->json('pasarguard_groups')->nullable()->after('pasarguard_mode');
            }
            if (! Schema::hasColumn('servers', 'pasarguard_groups_synced_at')) {
                $table->timestamp('pasarguard_groups_synced_at')->nullable()->after('pasarguard_groups');
            }
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['pasarguard_groups', 'pasarguard_groups_synced_at']);
        });
    }
};
