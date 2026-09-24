<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ستون «host» فقط نشانی مدیریتی است: همان چیزی که شاه‌پنل با آن به API پنل وصل
 * می‌شود و معمولاً IP مستقیم سرور خارج است. فروشنده‌ای که ترافیک کاربرانش را از
 * تونل/رله عبور می‌دهد به نشانی دومی نیاز دارد تا ارتباط مدیریتی سر جای خودش
 * بماند ولی کانفیگ‌ها و لینک اشتراکِ کاربر به تونل اشاره کنند.
 *
 * خالی‌بودن هر دو ستون یعنی «مثل قبل رفتار کن»؛ نصب‌های موجود بعد از migrate
 * هیچ تغییر رفتاری نمی‌بینند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'client_host')) {
                $table->string('client_host')->nullable()->after('host');
            }

            if (! Schema::hasColumn('servers', 'client_port')) {
                // رله معمولاً پورت را هم جابه‌جا می‌کند؛ null یعنی «پورت
                // اینباند/کانفیگ را دست نزن».
                $table->unsignedInteger('client_port')->nullable()->after('client_host');
            }
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'client_port')) {
                $table->dropColumn('client_port');
            }

            if (Schema::hasColumn('servers', 'client_host')) {
                $table->dropColumn('client_host');
            }
        });
    }
};
