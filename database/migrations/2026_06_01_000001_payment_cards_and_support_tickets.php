<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_cards')) {
            Schema::create('payment_cards', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('card_number', 19);
                $table->string('card_holder')->nullable();
                $table->string('bank_name')->nullable();
                $table->text('instructions')->nullable();
                $table->string('approval_status', 20)->default('pending');
                $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('deletion_requested_at')->nullable();
                $table->foreignId('deletion_approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('deletion_approved_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['user_id', 'approval_status']);
            });
        }

        if (Schema::hasTable('user_payment_cards')) {
            $rows = DB::table('user_payment_cards')->get();

            foreach ($rows as $row) {
                DB::table('payment_cards')->insert([
                    'user_id' => $row->user_id,
                    'card_number' => $row->card_number,
                    'card_holder' => $row->card_holder,
                    'bank_name' => $row->bank_name,
                    'instructions' => $row->instructions,
                    'approval_status' => 'approved',
                    'approved_at' => $row->updated_at ?? now(),
                    'is_active' => (bool) ($row->is_active ?? true),
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);
            }

            Schema::drop('user_payment_cards');
        }

        if (! Schema::hasTable('support_departments')) {
            Schema::create('support_departments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['owner_user_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $table): void {
                $table->id();
                $table->string('ticket_number', 32)->unique();
                $table->string('subject');
                $table->string('status', 20)->default('open');
                $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('department_id')->nullable()->constrained('support_departments')->nullOnDelete();
                $table->foreignId('source_ticket_id')->nullable()->constrained('support_tickets')->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamp('reopened_at')->nullable();
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamps();

                $table->index(['assignee_user_id', 'status']);
                $table->index(['requester_user_id', 'status']);
            });
        }

        if (! Schema::hasTable('support_ticket_messages')) {
            Schema::create('support_ticket_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->text('body');
                $table->boolean('is_internal')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('support_departments');
        Schema::dropIfExists('payment_cards');
    }
};
