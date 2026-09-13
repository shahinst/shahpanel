<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 18, 2);
            $table->decimal('balance_before', 18, 2);
            $table->decimal('balance_after', 18, 2);
            $table->foreignId('source_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('related_account_id')->nullable();
            $table->unsignedBigInteger('related_invoice_id')->nullable();
            $table->unsignedBigInteger('related_payment_request_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('related_invoice_id');
            $table->index('wallet_id');
            $table->index('related_account_id');
            $table->index('related_payment_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
