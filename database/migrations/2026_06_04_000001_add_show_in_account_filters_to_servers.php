<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'show_in_account_filters')) {
                $table->boolean('show_in_account_filters')->default(true)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'show_in_account_filters')) {
                $table->dropColumn('show_in_account_filters');
            }
        });
    }
};
