<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نمایندهٔ اختصاصی: نماینده‌ای که سرور خودش را دارد و پنل فقط ابزار فروش
 * است. هر سرور حداکثر مال یک نماینده است، پس server_id یکتاست.
 *
 * meter_interface اینترفیسی است که مصرف کل سرور از آن خوانده می‌شود (معمولاً
 * ether رو به اینترنت). روتر شمارنده‌اش را با ریبوت صفر می‌کند، پس
 * last_counter نگه داشته می‌شود تا افت شمارنده ریست تشخیص داده شود و جمع
 * کل هرگز عقب نرود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dedicated_servers')) {
            Schema::create('dedicated_servers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('server_id')->unique()->constrained('servers')->cascadeOnDelete();
                $table->string('meter_interface', 64)->nullable();
                $table->unsignedBigInteger('last_counter')->nullable();
                $table->unsignedBigInteger('total_rx_bytes')->default(0);
                $table->timestamp('last_read_at')->nullable();
                $table->string('last_error', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('dedicated_usage_days')) {
            Schema::create('dedicated_usage_days', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
                $table->date('day');
                $table->unsignedBigInteger('rx_bytes')->default(0);
                $table->unique(['server_id', 'day']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dedicated_usage_days');
        Schema::dropIfExists('dedicated_servers');
    }
};
