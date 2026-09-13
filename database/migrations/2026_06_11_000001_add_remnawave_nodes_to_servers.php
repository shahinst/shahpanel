<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'remnawave_nodes')) {
                $table->json('remnawave_nodes')->nullable()->after('remnawave_squads_synced_at');
            }
            if (! Schema::hasColumn('servers', 'remnawave_nodes_synced_at')) {
                $table->timestamp('remnawave_nodes_synced_at')->nullable()->after('remnawave_nodes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'remnawave_nodes_synced_at')) {
                $table->dropColumn('remnawave_nodes_synced_at');
            }
            if (Schema::hasColumn('servers', 'remnawave_nodes')) {
                $table->dropColumn('remnawave_nodes');
            }
        });
    }
};
