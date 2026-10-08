<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a card receipt can be recognised by when it is sent again: Telegram's
 * id of the picture and the tracking number typed under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shahbot_payments') && ! Schema::hasColumn('shahbot_payments', 'receipt_unique_id')) {
            Schema::table('shahbot_payments', function (Blueprint $table): void {
                $table->string('receipt_unique_id', 64)->nullable()->index()->after('receipt_note');
                $table->string('receipt_ref', 64)->nullable()->index()->after('receipt_unique_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shahbot_payments', 'receipt_unique_id')) {
            Schema::table('shahbot_payments', function (Blueprint $table): void {
                $table->dropIndex(['receipt_unique_id']);
                $table->dropIndex(['receipt_ref']);
                $table->dropColumn(['receipt_unique_id', 'receipt_ref']);
            });
        }
    }
};
