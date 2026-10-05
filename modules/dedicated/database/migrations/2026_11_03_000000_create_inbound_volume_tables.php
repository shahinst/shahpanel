<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid volume for inbound agents.
 *
 *  - inbound_volume_packs: what the admin offers an inbound agent: so many
 *    gigabytes on a given server for a given price.
 *  - inbound_charge_requests: an agent asking for one of those packs, in the
 *    same shape as a wallet top-up request. The admin settles the volume that
 *    is actually given; the agent's wallet pays for it then, never earlier,
 *    so a refused request costs the agent nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inbound_volume_packs')) {
            Schema::create('inbound_volume_packs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('server_id')->constrained()->cascadeOnDelete();
                $table->string('title', 120);
                $table->unsignedInteger('gb');
                $table->decimal('price', 18, 2);
                $table->string('currency', 8)->default('IRT');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inbound_charge_requests')) {
            Schema::create('inbound_charge_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('allocation_id')->constrained('inbound_allocations')->cascadeOnDelete();
                $table->foreignId('pack_id')->nullable()->constrained('inbound_volume_packs')->nullOnDelete();
                $table->unsignedInteger('requested_gb');
                $table->decimal('amount', 18, 2);
                $table->string('currency', 8)->default('IRT');
                $table->string('status', 16)->default('pending')->index();
                $table->unsignedInteger('approved_gb')->nullable();
                $table->decimal('charged_amount', 18, 2)->nullable();
                $table->text('note')->nullable();
                $table->text('admin_note')->nullable();
                $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_charge_requests');
        Schema::dropIfExists('inbound_volume_packs');
    }
};
