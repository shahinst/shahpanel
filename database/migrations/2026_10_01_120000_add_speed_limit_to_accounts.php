<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts')) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table): void {
            // سقف سرعت هر اکانت، برای صف (simple queue) روی میکروتیک.
            //
            // دو ستون جدا و نه یک رشتهٔ «۲M/۱۰M»، چون پنل باید بتواند مقدار قبلی
            // را با مقدار جدید مقایسه کند تا فقط وقتی این فیلد عوض شده به روتر
            // درخواست بفرستد؛ مقایسهٔ عدد با عدد قابل اعتماد است و رشته نه.
            //
            // NULL یعنی «بی‌حد» — همان رفتار امروز اکانت‌هایی که هیچ صفی ندارند.
            // عمداً هیچ بک‌فیلی انجام نمی‌شود: هر اکانتی که الان هست بدون سقف
            // می‌ماند و بعد از به‌روزرسانی دقیقاً مثل قبل کار می‌کند.
            if (! Schema::hasColumn('accounts', 'speed_limit_up_kbps')) {
                $table->unsignedInteger('speed_limit_up_kbps')->nullable()->after('data_limit_bytes');
            }

            if (! Schema::hasColumn('accounts', 'speed_limit_down_kbps')) {
                $table->unsignedInteger('speed_limit_down_kbps')->nullable()->after('speed_limit_up_kbps');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('accounts')) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table): void {
            foreach (['speed_limit_up_kbps', 'speed_limit_down_kbps'] as $column) {
                if (Schema::hasColumn('accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
