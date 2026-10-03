<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lucky wheel (one spin per user per cooldown) and lotteries among the
 * users who bought during a period.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shahbot_wheel_spins')) {
            Schema::create('shahbot_wheel_spins', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('bot_user_id')->constrained('shahbot_users')->cascadeOnDelete();
                $table->string('prize_label', 128);
                $table->string('prize_type', 20);
                $table->decimal('prize_value', 16, 2)->default(0);
                $table->string('code', 64)->nullable();
                $table->timestamps();
                $table->index(['bot_user_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('shahbot_lotteries')) {
            Schema::create('shahbot_lotteries', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('bot_id')->default(0)->index();
                $table->string('title', 160);
                $table->text('description')->nullable();
                $table->decimal('prize_amount', 16, 2);
                $table->unsignedInteger('winners_count')->default(1);
                $table->timestamp('starts_at');
                $table->timestamp('draw_at')->index();
                $table->string('status', 20)->default('open')->index(); // open | drawn | cancelled
                $table->json('winners')->nullable();
                $table->unsignedInteger('participants')->default(0);
                $table->timestamp('drawn_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shahbot_lotteries');
        Schema::dropIfExists('shahbot_wheel_spins');
    }
};
