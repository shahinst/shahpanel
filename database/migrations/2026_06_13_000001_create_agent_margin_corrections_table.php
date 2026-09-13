<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_margin_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('margin_transaction_id')->nullable();
            $table->decimal('expected_margin', 18, 2);
            $table->decimal('actual_margin', 18, 2);
            $table->decimal('clawback_amount', 18, 2);
            $table->unsignedBigInteger('clawback_transaction_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('margin_transaction_id');
            $table->index(['agent_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_margin_corrections');
    }
};
