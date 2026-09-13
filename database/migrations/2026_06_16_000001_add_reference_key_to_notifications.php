<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'reference_key')) {
                $table->string('reference_key', 191)->nullable()->after('link');
                $table->index(['user_id', 'type', 'reference_key', 'created_at'], 'notifications_dedup_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            if (Schema::hasColumn('notifications', 'reference_key')) {
                $table->dropIndex('notifications_dedup_idx');
                $table->dropColumn('reference_key');
            }
        });
    }
};
