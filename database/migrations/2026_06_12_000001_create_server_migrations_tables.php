<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('server_migrations')) {
            return;
        }

        Schema::create('server_migrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('to_server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('total_accounts')->default(0);
            $table->unsignedInteger('migrated_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('account_ids')->nullable();
            $table->boolean('dry_run')->default(false);
            $table->text('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('server_migration_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_migration_id')->constrained('server_migrations')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('remote_username')->nullable();
            $table->string('status', 32);
            $table->text('message')->nullable();
            $table->string('subscription_url', 2048)->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('data_limit_bytes')->nullable();
            $table->unsignedBigInteger('data_used_bytes')->nullable();
            $table->timestamp('expiry_at')->nullable();
            $table->timestamps();

            $table->index(['server_migration_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_migration_entries');
        Schema::dropIfExists('server_migrations');
    }
};
