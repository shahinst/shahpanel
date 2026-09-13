<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'pasarguard_mode')) {
                $table->string('pasarguard_mode', 32)->default('reseller')->after('type');
            }
        });

        Schema::table('packages', function (Blueprint $table) {
            if (! Schema::hasColumn('packages', 'pasarguard_group_id')) {
                // max_data_gb در migration بعدی (2026_06_05) ساخته می‌شود؛ after حذف شد تا
                // روی نصب تازه که این فایل زودتر اجرا می‌شود خطای ستون ناموجود ندهد.
                $table->unsignedBigInteger('pasarguard_group_id')->nullable();
            }
            if (! Schema::hasColumn('packages', 'pasarguard_hwid_limit')) {
                $table->unsignedSmallInteger('pasarguard_hwid_limit')->nullable()->after('pasarguard_group_id');
            }
            if (! Schema::hasColumn('packages', 'pasarguard_expiry_activation')) {
                $table->string('pasarguard_expiry_activation', 32)->default('from_creation')->after('pasarguard_hwid_limit');
            }
        });

        Schema::table('accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts', 'pasarguard_user_id')) {
                $table->unsignedBigInteger('pasarguard_user_id')->nullable()->after('sanaei_sub_id');
            }
            if (! Schema::hasColumn('accounts', 'pasarguard_subscription_url')) {
                $table->string('pasarguard_subscription_url', 512)->nullable()->after('pasarguard_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['pasarguard_user_id', 'pasarguard_subscription_url']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['pasarguard_group_id', 'pasarguard_hwid_limit', 'pasarguard_expiry_activation']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('pasarguard_mode');
        });
    }
};
