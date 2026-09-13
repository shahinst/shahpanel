<?php

use App\Enums\CommissionPayer;
use App\Enums\PaymentGatewayDriver;
use App\Enums\PaymentGatewayMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 40)->unique();
            $table->string('display_name', 120);
            $table->boolean('is_enabled')->default(false);
            $table->string('mode', 10)->default(PaymentGatewayMode::Test->value);
            $table->json('config')->nullable();
            $table->decimal('commission_percent', 8, 4)->default(0);
            $table->decimal('commission_fixed', 18, 2)->default(0);
            $table->string('commission_payer', 10)->default(CommissionPayer::User->value);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('gateway_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_gateway_id')->constrained()->cascadeOnDelete();
            $table->string('driver', 40);
            $table->string('status', 30);
            $table->decimal('amount_usdt', 18, 8)->nullable();
            $table->decimal('usdt_toman_rate', 18, 2)->nullable();
            $table->decimal('gross_toman', 18, 2);
            $table->decimal('commission_toman', 18, 2)->default(0);
            $table->decimal('net_toman', 18, 2);
            $table->string('commission_payer', 10);
            $table->string('external_payment_id', 120)->nullable();
            $table->string('external_invoice_id', 120)->nullable();
            $table->string('pay_address', 255)->nullable();
            $table->string('pay_currency', 32)->nullable();
            $table->decimal('pay_amount', 18, 8)->nullable();
            $table->string('invoice_url', 500)->nullable();
            $table->json('callback_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['driver', 'status']);
            $table->index('external_payment_id');
            $table->index('external_invoice_id');
        });

        $now = now();

        DB::table('payment_gateways')->insert([
            [
                'driver' => PaymentGatewayDriver::NowPayments->value,
                'display_name' => 'NOWPayments (Crypto)',
                'is_enabled' => false,
                'mode' => PaymentGatewayMode::Test->value,
                'config' => json_encode(['pay_currency' => 'usdttrc20'], JSON_UNESCAPED_UNICODE),
                'commission_percent' => 0,
                'commission_fixed' => 0,
                'commission_payer' => CommissionPayer::User->value,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'driver' => PaymentGatewayDriver::Zarinpal->value,
                'display_name' => 'زرین‌پال',
                'is_enabled' => false,
                'mode' => PaymentGatewayMode::Test->value,
                'config' => null,
                'commission_percent' => 0,
                'commission_fixed' => 0,
                'commission_payer' => CommissionPayer::User->value,
                'sort_order' => 20,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'driver' => PaymentGatewayDriver::CardToCard->value,
                'display_name' => 'کارت به کارت',
                'is_enabled' => false,
                'mode' => PaymentGatewayMode::Test->value,
                'config' => null,
                'commission_percent' => 0,
                'commission_fixed' => 0,
                'commission_payer' => CommissionPayer::User->value,
                'sort_order' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_payments');
        Schema::dropIfExists('payment_gateways');
    }
};
