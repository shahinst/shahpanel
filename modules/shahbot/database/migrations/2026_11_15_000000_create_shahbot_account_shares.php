<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Family sharing: an account its buyer lets other bot users see and connect
 * with, while renewing and paying stay with the buyer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shahbot_account_shares')) {
            Schema::create('shahbot_account_shares', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
                $table->foreignId('owner_bot_user_id')->constrained('shahbot_users')->cascadeOnDelete();
                $table->foreignId('member_bot_user_id')->constrained('shahbot_users')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['account_id', 'member_bot_user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shahbot_account_shares');
    }
};
