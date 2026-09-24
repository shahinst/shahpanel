<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * توکن اشتراک نمی‌تواند همان portal_token باشد: آن توکن با
     * PortalLinkService::issue() هر بار دوباره ساخته می‌شود و فقط چند دقیقه
     * (portal_link_ttl_minutes) اعتبار دارد، در حالی که نشانی /sub باید ماه‌ها
     * در اپلیکیشن کاربر بماند. sanaei_sub_id هم فقط برای اکانت‌های سنایی وجود
     * دارد و مالکش پنل راه دور است، پس ابطالش از این‌جا ممکن نیست.
     *
     * ستون nullable است و مقدارش در نخستین استفاده ساخته می‌شود؛ پس این مهاجرت
     * روی پنل‌های پرحجم هیچ backfill سنگینی انجام نمی‌دهد.
     */
    public function up(): void
    {
        if (Schema::hasColumn('accounts', 'subscription_token')) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('subscription_token', 64)->nullable()->after('portal_token_expires_at');
            $table->unique('subscription_token');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('accounts', 'subscription_token')) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique(['subscription_token']);
            $table->dropColumn('subscription_token');
        });
    }
};
