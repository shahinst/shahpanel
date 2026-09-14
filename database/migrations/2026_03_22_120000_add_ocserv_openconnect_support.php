<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OpenConnect (ocserv) provider — separate from Cisco ASA AnyConnect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            if (! Schema::hasColumn('servers', 'ocserv_vpn_address')) {
                $table->string('ocserv_vpn_address', 255)->nullable()->after('cisco_simultaneous_logins');
            }
            if (! Schema::hasColumn('servers', 'ocserv_default_max_sessions')) {
                $table->unsignedSmallInteger('ocserv_default_max_sessions')->default(1)->after('ocserv_vpn_address');
            }
            if (! Schema::hasColumn('servers', 'ocserv_group')) {
                $table->string('ocserv_group', 128)->nullable()->after('ocserv_default_max_sessions');
            }
            if (! Schema::hasColumn('servers', 'ocserv_verify_ssl')) {
                $table->boolean('ocserv_verify_ssl')->default(true)->after('ocserv_group');
            }
        });

        Schema::table('packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('packages', 'ocserv_max_sessions')) {
                $table->unsignedSmallInteger('ocserv_max_sessions')->nullable()->after('cisco_simultaneous_logins');
            }
            if (! Schema::hasColumn('packages', 'ocserv_group')) {
                $table->string('ocserv_group', 128)->nullable()->after('ocserv_max_sessions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            foreach (['ocserv_max_sessions', 'ocserv_group'] as $col) {
                if (Schema::hasColumn('packages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('servers', function (Blueprint $table): void {
            foreach ([
                'ocserv_vpn_address',
                'ocserv_default_max_sessions',
                'ocserv_group',
                'ocserv_verify_ssl',
            ] as $col) {
                if (Schema::hasColumn('servers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
