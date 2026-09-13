<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_ips', function (Blueprint $table): void {
            $table->id();
            $table->string('ip', 45)->unique();
            // login_bruteforce | escalated | manual
            $table->string('reason', 32)->default('login_bruteforce');
            $table->string('country_code', 2)->nullable();
            $table->string('country_name', 80)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_username', 191)->nullable();
            $table->string('last_user_agent', 255)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            // Null means "until an admin lifts it".
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('unblocked_at')->nullable();
            $table->foreignId('unblocked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // True once the OS firewall is also dropping this address.
            $table->boolean('in_firewall')->default(false);
            $table->timestamps();

            $table->index(['unblocked_at', 'expires_at']);
        });

        Schema::create('ip_whitelist', function (Blueprint $table): void {
            $table->id();
            // Single address or CIDR; never blocked whatever it does.
            $table->string('ip', 45)->unique();
            $table->string('note', 191)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('login_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('ip', 45);
            $table->string('username', 191)->nullable();
            $table->boolean('succeeded')->default(false);
            $table->string('user_agent', 255)->nullable();
            $table->string('path', 191)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['ip', 'created_at']);
        });

        // Offline IP→country lookup, so the admin list can show a flag without
        // calling out to a third party on every render.
        Schema::create('ip_country_ranges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('start_ip');
            $table->unsignedBigInteger('end_ip');
            $table->string('country_code', 2);

            $table->index(['start_ip', 'end_ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_country_ranges');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('ip_whitelist');
        Schema::dropIfExists('blocked_ips');
    }
};
