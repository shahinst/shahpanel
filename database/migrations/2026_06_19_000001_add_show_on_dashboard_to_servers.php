<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            if (! Schema::hasColumn('servers', 'show_on_dashboard')) {
                $table->boolean('show_on_dashboard')->default(true)->after('show_in_account_filters');
            }
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            if (Schema::hasColumn('servers', 'show_on_dashboard')) {
                $table->dropColumn('show_on_dashboard');
            }
        });
    }
};
