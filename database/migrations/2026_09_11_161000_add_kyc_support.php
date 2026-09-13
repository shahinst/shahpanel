<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('packages', 'kyc_required')) {
                $table->boolean('kyc_required')->default(false)->after('is_active');
            }
        });

        if (! Schema::hasTable('account_kyc_verifications')) {
            Schema::create('account_kyc_verifications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('initiated_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('owner_seller_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
                $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->string('status', 32)->default('draft')->index();
                $table->string('first_name');
                $table->string('last_name');
                $table->text('national_code_enc');
                $table->string('national_code_hash', 64)->index();
                $table->string('birth_date', 20);
                $table->text('card_number_enc');
                $table->string('card_number_last4', 4)->nullable();
                $table->string('document_disk', 32)->default('local');
                $table->text('document_path_enc')->nullable();
                $table->string('document_original_name')->nullable();
                $table->string('document_mime', 100)->nullable();
                $table->unsignedInteger('document_size')->nullable();
                $table->boolean('has_document')->default(false);
                $table->unsignedTinyInteger('verify_attempts')->default(0);
                $table->unsignedTinyInteger('max_verify_attempts')->default(2);
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('locked_at')->nullable();
                $table->timestamp('reset_requested_at')->nullable();
                $table->foreignId('reset_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reset_at')->nullable();
                $table->json('last_api_result')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_kyc_verifications');

        Schema::table('packages', function (Blueprint $table): void {
            if (Schema::hasColumn('packages', 'kyc_required')) {
                $table->dropColumn('kyc_required');
            }
        });
    }
};
