<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('packages', 'mikrotik_profile_key')) {
            Schema::table('packages', function (Blueprint $table): void {
                $table->string('mikrotik_profile_key', 128)->nullable()->after('default_server_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('packages', 'mikrotik_profile_key')) {
            Schema::table('packages', function (Blueprint $table): void {
                $table->dropColumn('mikrotik_profile_key');
            });
        }
    }
};
