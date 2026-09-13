<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-account fixed renewal price. When set, renewals charge exactly this
 * amount (0 = free) instead of recomputing from the buyer's wholesale price.
 * Null keeps the normal computed pricing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->decimal('renewal_charge_override', 20, 2)->nullable()->after('purchased_data_gb');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('renewal_charge_override');
        });
    }
};
