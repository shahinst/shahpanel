<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('commission_rate', 5, 4)->nullable()->after('daily_server_change_limit');
        });

        Schema::create('notification_broadcasts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('audience', 32);
            $table->string('status', 32)->default('pending');
            $table->string('title');
            $table->text('body');
            $table->string('link')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_broadcasts');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('commission_rate');
        });
    }
};
