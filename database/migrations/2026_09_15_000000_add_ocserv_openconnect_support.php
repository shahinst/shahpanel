<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ocserv / OpenConnect — dedicated server + package + account fields.
 * Isolated from Cisco ASA, MikroTik PPP/OpenVPN/L2TP and V2Ray panels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            if (! Schema::hasColumn('servers', 'ocserv_api_port')) {
                $table->unsignedSmallInteger('ocserv_api_port')->default(9443)->after('public_ip');
            }
            if (! Schema::hasColumn('servers', 'ocserv_vpn_hostname')) {
                $table->string('ocserv_vpn_hostname', 255)->nullable()->after('ocserv_api_port');
            }
            if (! Schema::hasColumn('servers', 'ocserv_group')) {
                $table->string('ocserv_group', 128)->nullable()->after('ocserv_vpn_hostname');
            }
            if (! Schema::hasColumn('servers', 'ocserv_max_sessions')) {
                $table->unsignedSmallInteger('ocserv_max_sessions')->default(1)->after('ocserv_group');
            }
            if (! Schema::hasColumn('servers', 'ocserv_verify_ssl')) {
                $table->boolean('ocserv_verify_ssl')->default(true)->after('ocserv_max_sessions');
            }
        });

        Schema::table('packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('packages', 'ocserv_group')) {
                $table->string('ocserv_group', 128)->nullable()->after('cisco_simultaneous_logins');
            }
            if (! Schema::hasColumn('packages', 'ocserv_max_sessions')) {
                $table->unsignedSmallInteger('ocserv_max_sessions')->nullable()->after('ocserv_group');
            }
        });

        Schema::table('accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounts', 'ocserv_username')) {
                $table->string('ocserv_username', 64)->nullable()->after('cisco_asa_username');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if (Schema::hasColumn('accounts', 'ocserv_username')) {
                $table->dropColumn('ocserv_username');
            }
        });

        Schema::table('packages', function (Blueprint $table): void {
            foreach (['ocserv_group', 'ocserv_max_sessions'] as $col) {
                if (Schema::hasColumn('packages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('servers', function (Blueprint $table): void {
            foreach ([
                'ocserv_api_port',
                'ocserv_vpn_hostname',
                'ocserv_group',
                'ocserv_max_sessions',
                'ocserv_verify_ssl',
            ] as $col) {
                if (Schema::hasColumn('servers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
