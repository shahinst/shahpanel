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
            if (! Schema::hasColumn('servers', 'ssh_port')) {
                $table->unsignedInteger('ssh_port')->nullable()->after('port');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('servers') || ! Schema::hasColumn('servers', 'ssh_port')) {
            return;
        }

        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('ssh_port');
        });
    }
};
