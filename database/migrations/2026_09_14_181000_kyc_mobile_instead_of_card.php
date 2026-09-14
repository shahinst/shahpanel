<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            if (! Schema::hasColumn('account_kyc_verifications', 'mobile_masked')) {
                $table->string('mobile_masked', 20)->nullable()->after('mobile_enc');
            }
        });

        // Legacy card columns stay for old rows, but new drafts no longer require a card.
        if (Schema::hasColumn('account_kyc_verifications', 'card_number_enc')) {
            DB::statement('ALTER TABLE account_kyc_verifications MODIFY card_number_enc TEXT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('account_kyc_verifications')) {
            return;
        }

        Schema::table('account_kyc_verifications', function (Blueprint $table): void {
            if (Schema::hasColumn('account_kyc_verifications', 'mobile_masked')) {
                $table->dropColumn('mobile_masked');
            }
            if (Schema::hasColumn('account_kyc_verifications', 'mobile_enc')) {
                $table->dropColumn('mobile_enc');
            }
        });
    }
};
