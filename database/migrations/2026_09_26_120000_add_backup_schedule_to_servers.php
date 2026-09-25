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
            if (! Schema::hasColumn('servers', 'backup_schedule_enabled')) {
                $table->boolean('backup_schedule_enabled')->default(false);
            }

            // فهرست ساعت‌های «HH:MM» به وقت پنل. رابطهٔ سرور با ساعت‌هایش یک‌به‌یک
            // است و هیچ صفت دیگری ندارد، پس یک ستون json دقیقاً مثل
            // pasarguard_groups/remnawave_squads از یک جدول جداگانه ساده‌تر است.
            if (! Schema::hasColumn('servers', 'backup_times')) {
                $table->json('backup_times')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('servers')) {
            return;
        }

        Schema::table('servers', function (Blueprint $table): void {
            if (Schema::hasColumn('servers', 'backup_times')) {
                $table->dropColumn('backup_times');
            }

            if (Schema::hasColumn('servers', 'backup_schedule_enabled')) {
                $table->dropColumn('backup_schedule_enabled');
            }
        });
    }
};
