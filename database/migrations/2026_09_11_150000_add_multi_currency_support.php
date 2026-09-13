<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('packages', 'currency')) {
                $table->string('currency', 10)->default('IRT')->after('base_price');
                $table->index('currency');
            }
        });

        DB::table('packages')->whereNull('currency')->orWhere('currency', '')->update(['currency' => 'IRT']);

        // Drop unique(user_id) so a user can hold one wallet per currency.
        $indexes = DB::select('SHOW INDEX FROM wallets WHERE Column_name = ? AND Non_unique = 0', ['user_id']);
        foreach ($indexes as $index) {
            $name = $index->Key_name ?? null;
            if ($name && $name !== 'PRIMARY') {
                try {
                    DB::statement("ALTER TABLE wallets DROP INDEX `{$name}`");
                } catch (\Throwable) {
                    // already dropped
                }
            }
        }

        // Some MySQL builds keep the legacy name even when the SHOW INDEX filter misses it.
        try {
            DB::statement('ALTER TABLE wallets DROP INDEX `wallets_user_id_unique`');
        } catch (\Throwable) {
            // absent
        }

        $hasComposite = collect(DB::select('SHOW INDEX FROM wallets'))
            ->contains(fn ($row) => ($row->Key_name ?? '') === 'wallets_user_id_currency_unique');

        if (! $hasComposite) {
            Schema::table('wallets', function (Blueprint $table): void {
                $table->unique(['user_id', 'currency'], 'wallets_user_id_currency_unique');
            });
        }

        DB::table('wallets')->whereNull('currency')->orWhere('currency', '')->update(['currency' => 'IRT']);

        Schema::table('transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('transactions', 'currency')) {
                $table->string('currency', 10)->default('IRT')->after('amount');
                $table->index('currency');
            }
        });

        DB::table('transactions')->whereNull('currency')->orWhere('currency', '')->update(['currency' => 'IRT']);

        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'currency')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->string('currency', 10)->default('IRT')->after('total');
                $table->index('currency');
            });
            DB::table('invoices')->whereNull('currency')->orWhere('currency', '')->update(['currency' => 'IRT']);
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'enabled_currencies')) {
                $table->json('enabled_currencies')->nullable()->after('status');
            }
            if (! Schema::hasColumn('users', 'settlement_currency')) {
                $table->string('settlement_currency', 10)->default('IRT')->after('enabled_currencies');
            }
        });

        // Existing users remain Toman-only.
        DB::table('users')->whereNull('settlement_currency')->orWhere('settlement_currency', '')->update([
            'settlement_currency' => 'IRT',
        ]);
        DB::table('users')->whereNull('enabled_currencies')->update([
            'enabled_currencies' => json_encode(['IRT']),
        ]);

        if (Schema::hasTable('payment_requests') && ! Schema::hasColumn('payment_requests', 'currency')) {
            Schema::table('payment_requests', function (Blueprint $table): void {
                $table->string('currency', 10)->default('IRT')->after('amount');
                $table->index('currency');
            });
            DB::table('payment_requests')->whereNull('currency')->orWhere('currency', '')->update([
                'currency' => 'IRT',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'settlement_currency')) {
                $table->dropColumn('settlement_currency');
            }
            if (Schema::hasColumn('users', 'enabled_currencies')) {
                $table->dropColumn('enabled_currencies');
            }
        });

        if (Schema::hasColumn('invoices', 'currency')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropIndex(['currency']);
                $table->dropColumn('currency');
            });
        }

        if (Schema::hasColumn('transactions', 'currency')) {
            Schema::table('transactions', function (Blueprint $table): void {
                $table->dropIndex(['currency']);
                $table->dropColumn('currency');
            });
        }

        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropUnique('wallets_user_id_currency_unique');
            $table->unique('user_id');
        });

        if (Schema::hasColumn('packages', 'currency')) {
            Schema::table('packages', function (Blueprint $table): void {
                $table->dropIndex(['currency']);
                $table->dropColumn('currency');
            });
        }
    }
};
