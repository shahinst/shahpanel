<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tunnel_groups') || ! Schema::hasColumn('tunnel_groups', 'balancing_mode')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `tunnel_groups` MODIFY `balancing_mode` VARCHAR(20) NOT NULL DEFAULT 'pcc'");
        } elseif ($driver === 'sqlite') {
            // SQLite has no real column-width limit for TEXT/VARCHAR; nothing to change.
            return;
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tunnel_groups ALTER COLUMN balancing_mode TYPE VARCHAR(20)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tunnel_groups') || ! Schema::hasColumn('tunnel_groups', 'balancing_mode')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `tunnel_groups` MODIFY `balancing_mode` VARCHAR(8) NOT NULL DEFAULT 'pcc'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tunnel_groups ALTER COLUMN balancing_mode TYPE VARCHAR(8)');
        }
    }
};
