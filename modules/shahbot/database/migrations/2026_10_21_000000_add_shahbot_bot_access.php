<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * چه کسی ربات اختصاصی دارد، به‌جای یک کلید سراسری برای همه.
 *
 * ادمین به نماینده‌ها (و فروشنده‌های مستقیم خودش) دسترسی می‌دهد و هر
 * نماینده به فروشنده‌های خودش. granted_by نگه داشته می‌شود تا وقتی دسترسی
 * نماینده برداشته شد معلوم باشد فروشنده‌هایش از چه کسی گرفته بودند.
 *
 * هر کسی که همین الان ربات دارد دسترسی می‌گیرد، تا بعد از آپدیت ربات هیچ
 * نماینده‌ای از کار نیفتد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shahbot_bot_access')) {
            Schema::create('shahbot_bot_access', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('shahbot_bots')) {
            $now = now();
            $existing = DB::table('shahbot_bot_access')->pluck('user_id')->all();

            DB::table('shahbot_bots')->pluck('owner_user_id')
                ->reject(fn ($id) => in_array($id, $existing, false))
                ->each(fn ($id) => DB::table('shahbot_bot_access')->insert([
                    'user_id' => $id, 'granted_by_user_id' => null, 'created_at' => $now, 'updated_at' => $now,
                ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shahbot_bot_access');
    }
};
