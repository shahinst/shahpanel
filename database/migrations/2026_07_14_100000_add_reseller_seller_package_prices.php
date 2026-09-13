<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Model 3 (discount pricing): per-seller, per-package price overrides an agent
 * sets from the seller-edit page, bounded by the agent's seller_markup_range_percent.
 * Empty by default — until an agent explicitly sets a price the seller keeps the
 * agent's uniform seller discount, so introducing this table causes no price change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reseller_seller_package_prices')) {
            return;
        }

        Schema::create('reseller_seller_package_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->decimal('wholesale_price', 12, 2);
            $table->timestamps();
            $table->unique(['seller_user_id', 'package_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_seller_package_prices');
    }
};
