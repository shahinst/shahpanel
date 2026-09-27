<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            // فهرست کلیدهای بخش‌های مجاز (config/admin_sections.php).
            //
            // NULL یعنی «همهٔ بخش‌ها». عمداً هیچ بک‌فیلی انجام نمی‌شود: هر
            // ادمینی که امروز وجود دارد با ستون خالی می‌ماند و بعد از
            // به‌روزرسانی دقیقاً همان دسترسی امروزش را دارد. اگر جای این، پیش‌فرض
            // را «هیچ بخش» می‌گذاشتیم، صاحب پنل با یک آپدیت از پنل خودش بیرون
            // می‌افتاد.
            //
            // یک ستون json به‌جای جدول واسط، چون رابطه یک‌به‌چند ساده است و هیچ
            // صفت دیگری روی هر دسترسی وجود ندارد — همان الگویی که
            // enabled_currencies روی همین جدول دارد.
            if (! Schema::hasColumn('users', 'admin_section_permissions')) {
                $table->json('admin_section_permissions')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'admin_section_permissions')) {
                $table->dropColumn('admin_section_permissions');
            }
        });
    }
};
