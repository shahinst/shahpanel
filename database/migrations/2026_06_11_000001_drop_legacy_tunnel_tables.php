<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes every table of the legacy tunneling system. Run
 * `php artisan tunnels:legacy-teardown --apply` BEFORE migrating so the
 * router-side objects can still be located from these tables.
 */
return new class extends Migration
{
    /** Children first (FK order). */
    private const TABLES = [
        'tunnel_health_checks',
        'tunnel_diagnostic_runs',
        'tunnel_monitor_samples',
        'tunnel_events',
        'tunnel_identifiers',
        'provision_jobs',
        'tunnel_links',
        'tunnel_exits',
        'tunnels',
        'server_loopbacks',
    ];

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        if (Schema::hasTable('servers') && Schema::hasColumn('servers', 'ros_capabilities')) {
            Schema::table('servers', function (Blueprint $table): void {
                $table->dropColumn('ros_capabilities');
            });
        }
    }

    public function down(): void
    {
        // Legacy system is gone for good — no recreate path.
    }
};
