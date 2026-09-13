<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounts', 'mikrotik_profile_key')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->string('mikrotik_profile_key', 128)->nullable()->after('server_id');
                $table->index(['server_id', 'mikrotik_profile_key']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accounts', 'mikrotik_profile_key')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->dropIndex(['server_id', 'mikrotik_profile_key']);
                $table->dropColumn('mikrotik_profile_key');
            });
        }
    }
};
