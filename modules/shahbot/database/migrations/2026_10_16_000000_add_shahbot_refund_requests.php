<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shahbot_refund_requests')) {
            Schema::create('shahbot_refund_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('bot_user_id')->constrained('shahbot_users')->cascadeOnDelete();
                $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
                $table->text('reason')->nullable();
                $table->string('status', 20)->default('pending')->index();
                $table->decimal('amount', 16, 2)->nullable();
                $table->string('reviewed_by', 128)->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shahbot_refund_requests');
    }
};
