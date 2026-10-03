<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online payments of the bot: gateway top-ups (ZarinPal, crypto) are panel
 * GatewayPayments linked to the bot user here, so the bot can tell them when
 * the payment lands; Telegram Stars payments are bot payments with the charge
 * id Telegram returns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shahbot_gateway_payments')) {
            Schema::create('shahbot_gateway_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('bot_user_id')->constrained('shahbot_users')->cascadeOnDelete();
                $table->foreignId('gateway_payment_id')->unique()->constrained('gateway_payments')->cascadeOnDelete();
                $table->timestamp('notified_at')->nullable()->index();
                $table->timestamps();
            });
        }

        Schema::table('shahbot_payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('shahbot_payments', 'stars')) {
                $table->unsignedInteger('stars')->nullable()->after('amount');
            }
            if (! Schema::hasColumn('shahbot_payments', 'external_id')) {
                $table->string('external_id', 191)->nullable()->unique()->after('receipt_note');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shahbot_gateway_payments');

        Schema::table('shahbot_payments', function (Blueprint $table): void {
            foreach (['external_id', 'stars'] as $column) {
                if (Schema::hasColumn('shahbot_payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
