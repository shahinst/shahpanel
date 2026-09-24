<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * چرا: تا امروز هر اکانت ثنایی روی «همه inboundهای فعال» ساخته می‌شد و
     * چون 3x-ui ایمیل کلاینت را در کل پنل یکتا می‌داند، از inbound دوم به بعد
     * خطای Duplicate email می‌گرفتیم. حالا ادمین روی خودِ پکیج مشخص می‌کند
     * اکانت‌های آن پکیج روی کدام inboundها ساخته شوند.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('packages', 'sanaei_inbound_ids')) {
                // NULL یا آرایه خالی یعنی «همه inboundهای فعال» تا پکیج‌های
                // موجود بدون هیچ تنظیمی دقیقاً مثل قبل کار کنند.
                $table->json('sanaei_inbound_ids')->nullable()->after('remnawave_traffic_strategy');
            }
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            if (Schema::hasColumn('packages', 'sanaei_inbound_ids')) {
                $table->dropColumn('sanaei_inbound_ids');
            }
        });
    }
};
