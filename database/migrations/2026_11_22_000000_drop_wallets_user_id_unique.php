<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The multi-currency migration meant to replace unique(user_id) on wallets
 * with unique(user_id, currency), but it dropped the old key first: MySQL
 * refuses to drop the only index backing the user_id foreign key, the
 * migration swallowed that error, and then added the composite key next to
 * the old one. Every install kept both, so the first user granted a second
 * currency broke every page that opens their wallets with a duplicate-key
 * error. Adding the composite key first lets the old one go.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql' || ! Schema::hasTable('wallets')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('wallets'));

        if (! $indexes->contains(fn (array $index): bool => $index['name'] === 'wallets_user_id_currency_unique')) {
            Schema::table('wallets', function (Blueprint $table): void {
                $table->unique(['user_id', 'currency'], 'wallets_user_id_currency_unique');
            });
        }

        foreach ($indexes as $index) {
            if ($index['unique'] && ! $index['primary'] && $index['columns'] === ['user_id']) {
                DB::statement('ALTER TABLE `wallets` DROP INDEX `'.$index['name'].'`');
            }
        }
    }

    public function down(): void
    {
        // A second wallet per user may exist by now; the old key cannot come back.
    }
};
