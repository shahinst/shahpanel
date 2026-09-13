<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agent "seller markup range": when set (e.g. 30), the agent may set their
 * sellers' price up to that % above the agent's own price, from their panel.
 * NULL = the agent cannot self-set seller prices (admin controls them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'seller_markup_range_percent')) {
                $table->decimal('seller_markup_range_percent', 7, 4)->nullable()->after('seller_discount_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'seller_markup_range_percent')) {
                $table->dropColumn('seller_markup_range_percent');
            }
        });
    }
};
