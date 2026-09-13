<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('servers')) {
            return;
        }

        Schema::table('servers', function (Blueprint $table): void {
            if (! Schema::hasColumn('servers', 'wireguard_persistent_keepalive')) {
                $table->unsignedSmallInteger('wireguard_persistent_keepalive')
                    ->nullable()
                    ->after('max_accounts');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('servers')) {
            return;
        }

        Schema::table('servers', function (Blueprint $table): void {
            if (Schema::hasColumn('servers', 'wireguard_persistent_keepalive')) {
                $table->dropColumn('wireguard_persistent_keepalive');
            }
        });
    }
};
