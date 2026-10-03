<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound resellers: the admin hands an agent one or more inbounds on a
 * Sanaei server together with a traffic quota and a price per gigabyte. The
 * agent builds their own packages on it and sells to their own sellers; the
 * panel bills the agent's wallet for the traffic their accounts consume.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inbound_allocations')) {
            Schema::create('inbound_allocations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('server_id')->constrained()->cascadeOnDelete();
                $table->string('title')->nullable();
                $table->json('inbound_ids');
                $table->unsignedBigInteger('quota_bytes');
                $table->decimal('price_per_gb', 16, 2);
                $table->string('currency', 8)->default('IRT');
                // How far below zero the agent's wallet may go before the
                // allocation is suspended. 0 = no credit.
                $table->decimal('credit_limit', 16, 2)->default(0);
                $table->string('status', 16)->default('active');
                $table->string('suspended_reason', 32)->nullable();
                $table->json('suspended_account_ids')->nullable();
                $table->unsignedBigInteger('used_bytes')->default(0);
                $table->unsignedBigInteger('billed_bytes')->default(0);
                $table->decimal('billed_amount', 18, 2)->default(0);
                // Last account_usage_logs.id already metered.
                $table->unsignedBigInteger('usage_watermark')->default(0);
                $table->timestamp('last_billed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['agent_user_id', 'status']);
            });
        }

        Schema::table('packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('packages', 'owner_agent_id')) {
                $table->foreignId('owner_agent_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('packages', 'inbound_allocation_id')) {
                $table->foreignId('inbound_allocation_id')->nullable()->after('owner_agent_id')->constrained('inbound_allocations')->nullOnDelete();
            }
        });

        Schema::table('accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounts', 'inbound_allocation_id')) {
                $table->foreignId('inbound_allocation_id')->nullable()->after('package_id')->constrained('inbound_allocations')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if (Schema::hasColumn('accounts', 'inbound_allocation_id')) {
                $table->dropConstrainedForeignId('inbound_allocation_id');
            }
        });

        Schema::table('packages', function (Blueprint $table): void {
            if (Schema::hasColumn('packages', 'inbound_allocation_id')) {
                $table->dropConstrainedForeignId('inbound_allocation_id');
            }

            if (Schema::hasColumn('packages', 'owner_agent_id')) {
                $table->dropConstrainedForeignId('owner_agent_id');
            }
        });

        Schema::dropIfExists('inbound_allocations');
    }
};
