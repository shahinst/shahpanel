<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel admin credentials are POSTed to the Sanaei (3x-ui) panel on every login
 * and the ASA privilege-15 credentials on every Cisco provision, so certificate
 * verification is on by default. A panel with a self-signed certificate can be
 * excepted per server from the server edit form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'sanaei_verify_ssl')) {
                $table->boolean('sanaei_verify_ssl')->default(true)->after('web_base_path');
            }
        });

        // New Cisco servers verify by default too; existing rows keep their value.
        if (Schema::hasColumn('servers', 'cisco_verify_ssl')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->boolean('cisco_verify_ssl')->default(true)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'sanaei_verify_ssl')) {
                $table->dropColumn('sanaei_verify_ssl');
            }
        });

        if (Schema::hasColumn('servers', 'cisco_verify_ssl')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->boolean('cisco_verify_ssl')->default(false)->change();
            });
        }
    }
};
