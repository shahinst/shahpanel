<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cisco AnyConnect (ASA / Secure Firewall) — dedicated server + package + account fields.
 * Isolated from MikroTik PPP/OpenVPN/L2TP and V2Ray panels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            if (! Schema::hasColumn('servers', 'cisco_vpn_hostname')) {
                $table->string('cisco_vpn_hostname', 255)->nullable()->after('public_ip');
            }
            if (! Schema::hasColumn('servers', 'cisco_group_policy')) {
                $table->string('cisco_group_policy', 128)->nullable()->after('cisco_vpn_hostname');
            }
            if (! Schema::hasColumn('servers', 'cisco_tunnel_group')) {
                $table->string('cisco_tunnel_group', 128)->nullable()->after('cisco_group_policy');
            }
            if (! Schema::hasColumn('servers', 'cisco_verify_ssl')) {
                $table->boolean('cisco_verify_ssl')->default(false)->after('cisco_tunnel_group');
            }
            if (! Schema::hasColumn('servers', 'cisco_write_memory')) {
                $table->boolean('cisco_write_memory')->default(true)->after('cisco_verify_ssl');
            }
            if (! Schema::hasColumn('servers', 'cisco_simultaneous_logins')) {
                $table->unsignedSmallInteger('cisco_simultaneous_logins')->default(1)->after('cisco_write_memory');
            }
        });

        Schema::table('packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('packages', 'cisco_group_policy')) {
                $table->string('cisco_group_policy', 128)->nullable()->after('remnawave_traffic_strategy');
            }
            if (! Schema::hasColumn('packages', 'cisco_tunnel_group')) {
                $table->string('cisco_tunnel_group', 128)->nullable()->after('cisco_group_policy');
            }
            if (! Schema::hasColumn('packages', 'cisco_simultaneous_logins')) {
                $table->unsignedSmallInteger('cisco_simultaneous_logins')->nullable()->after('cisco_tunnel_group');
            }
        });

        Schema::table('accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounts', 'cisco_asa_username')) {
                $table->string('cisco_asa_username', 64)->nullable()->after('remnawave_subscription_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if (Schema::hasColumn('accounts', 'cisco_asa_username')) {
                $table->dropColumn('cisco_asa_username');
            }
        });

        Schema::table('packages', function (Blueprint $table): void {
            foreach (['cisco_group_policy', 'cisco_tunnel_group', 'cisco_simultaneous_logins'] as $col) {
                if (Schema::hasColumn('packages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('servers', function (Blueprint $table): void {
            foreach ([
                'cisco_vpn_hostname',
                'cisco_group_policy',
                'cisco_tunnel_group',
                'cisco_verify_ssl',
                'cisco_write_memory',
                'cisco_simultaneous_logins',
            ] as $col) {
                if (Schema::hasColumn('servers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
