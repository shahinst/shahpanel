<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts marked for automatic renewal are renewed from their owner's wallet
 * when they expire, instead of being switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('accounts', 'auto_renew')) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('auto_renew')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('accounts', 'auto_renew')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->dropColumn('auto_renew');
            });
        }
    }
};
