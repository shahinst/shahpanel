<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_financial_plan_templates')) {
            Schema::create('agent_financial_plan_templates', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->decimal('credit_amount', 16, 2);
                $table->decimal('purchase_price', 16, 2);
                $table->decimal('discount_percent', 5, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_financial_plan_purchases')) {
            Schema::create('agent_financial_plan_purchases', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('template_id')->nullable()->constrained('agent_financial_plan_templates')->nullOnDelete();
                $table->string('name');
                $table->decimal('credit_total', 16, 2);
                $table->decimal('credit_remaining', 16, 2);
                $table->decimal('purchase_price', 16, 2);
                $table->decimal('discount_percent', 5, 2)->default(0);
                $table->string('status', 20)->default('active');
                $table->foreignId('sold_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('wallet_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
                $table->timestamp('purchased_at');
                $table->timestamps();

                $table->index(['agent_id', 'status', 'purchased_at'], 'afpp_agent_status_purchased_idx');
            });
        }

        if (! Schema::hasTable('agent_financial_plan_usages')) {
            Schema::create('agent_financial_plan_usages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('purchase_id')->constrained('agent_financial_plan_purchases')->cascadeOnDelete();
                $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('related_account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->foreignId('buyer_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
                $table->decimal('wholesale_portion', 16, 2);
                $table->decimal('discount_percent', 5, 2);
                $table->decimal('discount_amount', 16, 2);
                $table->decimal('charged_portion', 16, 2);
                $table->string('usage_type', 20)->default('purchase');
                $table->timestamps();

                $table->index(['related_account_id', 'usage_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_financial_plan_usages');
        Schema::dropIfExists('agent_financial_plan_purchases');
        Schema::dropIfExists('agent_financial_plan_templates');
    }
};
