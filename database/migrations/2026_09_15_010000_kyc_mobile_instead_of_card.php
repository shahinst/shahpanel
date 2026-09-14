<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('account_kyc_verifications')) {
            return;
        }

        Schema::table('account_kyc_verifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('account_kyc_verifications', 'mobile_enc')) {
                $table->text('mobile_enc')->nullable()->after('birth_date');
            }
            if (! Schema::hasColumn('account_kyc_verifications', 'mobile_last4')) {
                $table->string('mobile_last4', 4)->nullable()->after('mobile_enc');
            }
        });

        // card_number_enc was NOT NULL, so it must go or every new insert fails.
        Schema::table('account_kyc_verifications', function (Blueprint $table): void {
            foreach (['card_number_enc', 'card_number_last4'] as $column) {
                if (Schema::hasColumn('account_kyc_verifications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('account_kyc_verifications')) {
            return;
        }

        Schema::table('account_kyc_verifications', function (Blueprint $table): void {
            // Re-created as nullable: existing rows have no card number to back-fill,
            // so restoring the original NOT NULL constraint would abort the rollback.
            if (! Schema::hasColumn('account_kyc_verifications', 'card_number_enc')) {
                $table->text('card_number_enc')->nullable()->after('birth_date');
            }
            if (! Schema::hasColumn('account_kyc_verifications', 'card_number_last4')) {
                $table->string('card_number_last4', 4)->nullable()->after('card_number_enc');
            }
        });

        Schema::table('account_kyc_verifications', function (Blueprint $table): void {
            foreach (['mobile_enc', 'mobile_last4'] as $column) {
                if (Schema::hasColumn('account_kyc_verifications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
