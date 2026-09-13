<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->foreignId('client_user_id')
                ->nullable()
                ->after('owner_agent_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->index('client_user_id');
        });

        Schema::create('client_display_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_duration_id')->constrained()->cascadeOnDelete();
            $table->decimal('display_price', 18, 2);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'package_duration_id']);
        });

        Schema::create('user_payment_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('card_number', 19);
            $table->string('card_holder')->nullable();
            $table->string('bank_name')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_payment_cards');
        Schema::dropIfExists('client_display_prices');

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_user_id');
        });
    }
};
