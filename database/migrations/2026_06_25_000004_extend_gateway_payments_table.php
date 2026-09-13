<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('gateway_payments', 'amount_toman')) {
                $table->decimal('amount_toman', 18, 2)->nullable()->after('amount_usdt');
            }
            if (! Schema::hasColumn('gateway_payments', 'tracking_number')) {
                $table->string('tracking_number', 100)->nullable()->after('invoice_url');
            }
            if (! Schema::hasColumn('gateway_payments', 'card_last4')) {
                $table->string('card_last4', 4)->nullable()->after('tracking_number');
            }
            if (! Schema::hasColumn('gateway_payments', 'requester_note')) {
                $table->text('requester_note')->nullable()->after('card_last4');
            }
            if (! Schema::hasColumn('gateway_payments', 'reviewer_user_id')) {
                $table->foreignId('reviewer_user_id')->nullable()->after('requester_note')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('gateway_payments', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('reviewer_user_id');
            }
            if (! Schema::hasColumn('gateway_payments', 'admin_note')) {
                $table->text('admin_note')->nullable()->after('reviewed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gateway_payments', function (Blueprint $table) {
            if (Schema::hasColumn('gateway_payments', 'reviewer_user_id')) {
                $table->dropConstrainedForeignId('reviewer_user_id');
            }

            $columns = array_filter([
                Schema::hasColumn('gateway_payments', 'amount_toman') ? 'amount_toman' : null,
                Schema::hasColumn('gateway_payments', 'tracking_number') ? 'tracking_number' : null,
                Schema::hasColumn('gateway_payments', 'card_last4') ? 'card_last4' : null,
                Schema::hasColumn('gateway_payments', 'requester_note') ? 'requester_note' : null,
                Schema::hasColumn('gateway_payments', 'reviewed_at') ? 'reviewed_at' : null,
                Schema::hasColumn('gateway_payments', 'admin_note') ? 'admin_note' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
