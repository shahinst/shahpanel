<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'remnawave_active_squads')) {
                // remnawave_squads_synced_at در migration بعدی (2026_06_10) ساخته می‌شود؛
                // after حذف شد تا روی نصب تازه خطای ستون ناموجود ندهد.
                $table->json('remnawave_active_squads')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'remnawave_active_squads')) {
                $table->dropColumn('remnawave_active_squads');
            }
        });
    }
};
