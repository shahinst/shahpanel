<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * محتوای اشتراک باید محلی ذخیره شود، چون اندپوینت /sub/{token} اجازه ندارد
     * هنگام پاسخ‌دادن به پنل راه دور وصل شود (ربات‌های واسط تایم‌اوت ۳ ثانیه
     * دارند). برای سنایی هیچ نشانی اشتراکی در دیتابیس نیست و ساختنش نیازمند
     * POST /setting/all است، پس تنها راهِ سریع‌ماندن، نگه‌داشتنِ خودِ بدنه است.
     *
     * text (۶۴ کیلوبایت) برای چند صد خط کانفیگ کافی است؛ هر لینک vless/vmess
     * معمولاً زیر ۴۰۰ بایت است.
     *
     * بدون backfill: صف پس‌زمینه و زمان‌بندِ routes/console.php ردیف‌ها را
     * تدریجی پر می‌کنند تا این مهاجرت روی پنل‌های پرحجم قفل طولانی نسازد.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounts', 'subscription_cache')) {
                $table->text('subscription_cache')->nullable()->after('subscription_token');
            }

            if (! Schema::hasColumn('accounts', 'subscription_cached_at')) {
                $table->timestamp('subscription_cached_at')->nullable()->after('subscription_cache');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if (Schema::hasColumn('accounts', 'subscription_cached_at')) {
                $table->dropColumn('subscription_cached_at');
            }

            if (Schema::hasColumn('accounts', 'subscription_cache')) {
                $table->dropColumn('subscription_cache');
            }
        });
    }
};
