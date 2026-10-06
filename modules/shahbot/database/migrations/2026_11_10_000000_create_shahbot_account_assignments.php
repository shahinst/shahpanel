<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * هر بار که فروشنده یا نماینده اکانتی را به یکی از اعضای رباتش می‌دهد یک
     * ردیف اینجا ثبت می‌شود. صاحب قبلی اکانت (previous_client_user_id) نگه
     * داشته می‌شود تا اگر اشتباهی به آدم دیگری داده شد، برگرداندنش اکانت را
     * دقیقاً به همان حالت قبل ببرد. اکانت‌های موجود دست نمی‌خورند.
     */
    public function up(): void
    {
        if (Schema::hasTable('shahbot_account_assignments')) {
            return;
        }

        Schema::create('shahbot_account_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bot_id')->nullable()->index();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('bot_user_id')->constrained('shahbot_users')->cascadeOnDelete();
            $table->unsignedBigInteger('previous_client_user_id')->nullable();
            $table->unsignedBigInteger('client_user_id')->nullable();
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shahbot_account_assignments');
    }
};
