<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_broadcasts', function (Blueprint $table): void {
            if (! Schema::hasColumn('notification_broadcasts', 'image_path')) {
                $table->string('image_path')->nullable()->after('link');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'broadcast_banner_watermark_id')) {
                $table->unsignedBigInteger('broadcast_banner_watermark_id')->default(0)->after('telegram_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_broadcasts', function (Blueprint $table): void {
            if (Schema::hasColumn('notification_broadcasts', 'image_path')) {
                $table->dropColumn('image_path');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'broadcast_banner_watermark_id')) {
                $table->dropColumn('broadcast_banner_watermark_id');
            }
        });
    }
};
