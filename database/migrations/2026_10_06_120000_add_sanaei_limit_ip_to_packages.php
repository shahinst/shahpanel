<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many devices (distinct IPs) a Sanaei / 3x-ui account of this package may
 * use at once. Accounts were always created with limitIp 0 (no limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('packages', 'sanaei_limit_ip')) {
            return;
        }

        Schema::table('packages', function (Blueprint $table): void {
            $table->unsignedSmallInteger('sanaei_limit_ip')->nullable()->after('sanaei_inbound_ids');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('packages', 'sanaei_limit_ip')) {
            Schema::table('packages', function (Blueprint $table): void {
                $table->dropColumn('sanaei_limit_ip');
            });
        }
    }
};
