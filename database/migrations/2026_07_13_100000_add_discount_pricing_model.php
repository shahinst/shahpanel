<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of the discount-based pricing model (Model 3).
 *
 * Purely ADDITIVE: adds per-agent discount fields, an optional per-package
 * override table, and a `pricing_mode` flag that defaults to "legacy" so the
 * live system's economics are completely unchanged until we explicitly switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'reseller_discount_percent')) {
                // Agent discount off retail (da). Only meaningful for agents.
                $table->decimal('reseller_discount_percent', 5, 2)->nullable()->after('parent_id');
            }
            if (! Schema::hasColumn('users', 'seller_discount_percent')) {
                // Discount the agent grants their sellers (ds), 0..reseller_discount_percent.
                $table->decimal('seller_discount_percent', 5, 2)->nullable()->after('reseller_discount_percent');
            }
        });

        if (! Schema::hasTable('reseller_package_discounts')) {
            Schema::create('reseller_package_discounts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
                $table->decimal('discount_percent', 5, 2);            // per-package agent discount override
                $table->decimal('seller_discount_percent', 5, 2)->nullable(); // per-package seller discount override
                $table->timestamps();
                $table->unique(['agent_user_id', 'package_id']);
            });
        }

        if (! DB::table('settings')->where('key', 'pricing_mode')->exists()) {
            DB::table('settings')->insert([
                'key' => 'pricing_mode',
                'value' => 'legacy', // legacy | discount  — legacy = current behaviour, zero live impact
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_package_discounts');

        Schema::table('users', function (Blueprint $table): void {
            foreach (['reseller_discount_percent', 'seller_discount_percent'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        DB::table('settings')->where('key', 'pricing_mode')->delete();
    }
};
