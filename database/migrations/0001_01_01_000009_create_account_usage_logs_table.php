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
        Schema::create('account_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('rx_delta_bytes')->default(0);
            $table->unsignedBigInteger('tx_delta_bytes')->default(0);
            $table->unsignedBigInteger('rx_snapshot')->default(0);
            $table->unsignedBigInteger('tx_snapshot')->default(0);
            $table->timestamp('recorded_at');

            $table->index(['account_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_usage_logs');
    }
};
