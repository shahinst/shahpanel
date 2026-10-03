<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables of the Telegram sales bot module. Every table is prefixed shahbot_ so
 * the module never collides with the core, and each one is created only when
 * missing: activation runs these migrations again after every reinstall.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shahbot_settings')) {
            Schema::create('shahbot_settings', function (Blueprint $table): void {
                $table->string('key', 100)->primary();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shahbot_users')) {
            Schema::create('shahbot_users', function (Blueprint $table): void {
                $table->id();
                $table->bigInteger('telegram_id')->unique();
                $table->string('username', 64)->nullable()->index();
                $table->string('first_name', 128)->nullable();
                $table->string('last_name', 128)->nullable();
                $table->string('phone', 32)->nullable();
                $table->foreignId('client_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('referrer_id')->nullable()->constrained('shahbot_users')->nullOnDelete();
                $table->string('step', 64)->nullable();
                $table->json('step_data')->nullable();
                $table->boolean('is_blocked')->default(false);
                $table->boolean('bot_blocked_by_user')->default(false);
                $table->timestamp('rules_accepted_at')->nullable();
                $table->timestamp('test_used_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shahbot_payments')) {
            Schema::create('shahbot_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('bot_user_id')->constrained('shahbot_users')->cascadeOnDelete();
                $table->decimal('amount', 16, 2);
                $table->string('method', 20)->default('card');
                $table->string('status', 20)->default('awaiting_receipt')->index();
                $table->string('receipt_file_id')->nullable();
                $table->text('receipt_note')->nullable();
                $table->string('reviewed_by', 128)->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('reject_reason')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shahbot_orders')) {
            Schema::create('shahbot_orders', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('bot_user_id')->constrained('shahbot_users')->cascadeOnDelete();
                $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->foreignId('package_duration_id')->nullable()->constrained('package_durations')->nullOnDelete();
                $table->string('type', 20)->default('buy');
                $table->decimal('amount', 16, 2)->default(0);
                $table->decimal('discount', 16, 2)->default(0);
                $table->string('discount_code', 64)->nullable();
                $table->decimal('data_gb', 10, 2)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shahbot_codes')) {
            Schema::create('shahbot_codes', function (Blueprint $table): void {
                $table->id();
                $table->string('kind', 10)->index(); // discount | gift
                $table->string('code', 64)->unique();
                $table->string('value_type', 10)->default('fixed'); // fixed | percent
                $table->decimal('value', 16, 2);
                $table->decimal('min_amount', 16, 2)->nullable();
                $table->unsignedInteger('max_uses')->nullable();
                $table->unsignedInteger('used_count')->default(0);
                $table->boolean('first_purchase_only')->default(false);
                $table->timestamp('expires_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shahbot_code_uses')) {
            Schema::create('shahbot_code_uses', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('code_id')->constrained('shahbot_codes')->cascadeOnDelete();
                $table->foreignId('bot_user_id')->constrained('shahbot_users')->cascadeOnDelete();
                $table->decimal('amount', 16, 2);
                $table->timestamps();
                $table->unique(['code_id', 'bot_user_id']);
            });
        }

        if (! Schema::hasTable('shahbot_referral_rewards')) {
            Schema::create('shahbot_referral_rewards', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('referrer_id')->constrained('shahbot_users')->cascadeOnDelete();
                $table->foreignId('referred_id')->constrained('shahbot_users')->cascadeOnDelete();
                $table->foreignId('order_id')->nullable()->constrained('shahbot_orders')->nullOnDelete();
                $table->decimal('amount', 16, 2);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shahbot_tickets')) {
            Schema::create('shahbot_tickets', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('bot_user_id')->constrained('shahbot_users')->cascadeOnDelete();
                $table->string('status', 20)->default('open')->index(); // open | answered | closed
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shahbot_ticket_messages')) {
            Schema::create('shahbot_ticket_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('ticket_id')->constrained('shahbot_tickets')->cascadeOnDelete();
                $table->boolean('from_admin')->default(false);
                $table->string('author', 128)->nullable();
                $table->text('body');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shahbot_tutorials')) {
            Schema::create('shahbot_tutorials', function (Blueprint $table): void {
                $table->id();
                $table->string('title', 128);
                $table->text('body')->nullable();
                $table->string('url')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shahbot_broadcasts')) {
            Schema::create('shahbot_broadcasts', function (Blueprint $table): void {
                $table->id();
                $table->text('text');
                $table->string('audience', 20)->default('all'); // all | customers | no_service
                $table->string('status', 20)->default('queued')->index(); // queued | sending | done | cancelled
                $table->unsignedBigInteger('cursor')->default(0);
                $table->unsignedInteger('total')->default(0);
                $table->unsignedInteger('sent')->default(0);
                $table->unsignedInteger('failed')->default(0);
                $table->string('created_by', 128)->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'shahbot_broadcasts',
            'shahbot_tutorials',
            'shahbot_ticket_messages',
            'shahbot_tickets',
            'shahbot_referral_rewards',
            'shahbot_code_uses',
            'shahbot_codes',
            'shahbot_orders',
            'shahbot_payments',
            'shahbot_users',
            'shahbot_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
