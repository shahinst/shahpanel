<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پرداخت کارت‌به‌کارتی که برای یک سفارش مشخص است نه شارژ کیف پول.
 * سفارش (تعرفه، حجم، کد تخفیف، نام سرویس) کنار رسید نگه داشته می‌شود و
 * اکانت فقط بعد از تأیید رسید ساخته می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shahbot_payments') && ! Schema::hasColumn('shahbot_payments', 'order')) {
            Schema::table('shahbot_payments', function (Blueprint $table): void {
                $table->json('order')->nullable()->after('reject_reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shahbot_payments', 'order')) {
            Schema::table('shahbot_payments', fn (Blueprint $table) => $table->dropColumn('order'));
        }
    }
};
