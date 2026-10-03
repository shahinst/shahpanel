<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * هر ربات نمایندگی کاتالوگ خودش را دارد: کدام تعرفه در آن ربات دیده شود و
 * به چه قیمتی. ردیف‌ها به package_durations وصل می‌شوند، چون چیزی که در
 * فروشگاه ربات فروش می‌رود همان تعرفه است نه پکیج.
 *
 * نبودِ ردیف برای یک ربات یعنی «همه‌چیزِ کاتالوگ خودم» — یعنی ربات‌هایی که
 * از قبل کار می‌کردند بعد از این مهاجرت عیناً مثل قبل رفتار می‌کنند و کسی
 * یک‌شبه فروشگاهش خالی نمی‌شود.
 *
 * display_price اگر NULL باشد قیمت نمایشی خودِ مالک اعمال می‌شود. مقدار
 * دادن به آن فقط اجازهٔ گران‌کردن است؛ کفِ قیمت در لایهٔ اعتبارسنجی نگه
 * داشته می‌شود، چون قیمت زیر قیمت خودِ مالک یعنی هر فروش از کیف پول او کم
 * کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shahbot_bot_packages')) {
            return;
        }

        Schema::create('shahbot_bot_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bot_id')->constrained('shahbot_bots')->cascadeOnDelete();
            $table->foreignId('package_duration_id')->constrained('package_durations')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->decimal('display_price', 18, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['bot_id', 'package_duration_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shahbot_bot_packages');
    }
};
