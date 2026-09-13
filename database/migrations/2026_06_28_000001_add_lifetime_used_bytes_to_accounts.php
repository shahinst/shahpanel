<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts', 'lifetime_used_bytes')) {
                // Mirrors the remote panel's lifetime_used_traffic (total usage that never resets).
                $table->unsignedBigInteger('lifetime_used_bytes')->nullable()->after('data_used_bytes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounts', 'lifetime_used_bytes')) {
                $table->dropColumn('lifetime_used_bytes');
            }
        });
    }
};
