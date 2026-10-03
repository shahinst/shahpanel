<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shahbot_users', 'language')) {
            Schema::table('shahbot_users', function (Blueprint $table): void {
                $table->string('language', 5)->nullable()->after('phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shahbot_users', 'language')) {
            Schema::table('shahbot_users', fn (Blueprint $table) => $table->dropColumn('language'));
        }
    }
};
