<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per agent for their default markup, plus optional rows for a single
 * seller (seller_user_id set). No backfill: an agent without a row keeps the
 * per-seller prices they set by hand, exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_markups')) {
            return;
        }

        Schema::create('agent_markups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->decimal('percent', 5, 2);
            $table->timestamps();
            $table->unique(['agent_user_id', 'seller_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_markups');
    }
};
