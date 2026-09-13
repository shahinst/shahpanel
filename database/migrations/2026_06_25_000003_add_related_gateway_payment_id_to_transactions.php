<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('related_gateway_payment_id')->nullable()->after('related_payment_request_id');
            $table->index('related_gateway_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['related_gateway_payment_id']);
            $table->dropColumn('related_gateway_payment_id');
        });
    }
};
