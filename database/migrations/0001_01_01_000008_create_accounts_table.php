<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('owner_agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->foreignId('server_id')->constrained()->restrictOnDelete();
            $table->string('service_type', 30);
            $table->string('remote_username');
            $table->text('remote_password_enc')->nullable();
            $table->text('wireguard_private_key_enc')->nullable();
            $table->string('wireguard_public_key')->nullable();
            $table->string('wireguard_address')->nullable();
            $table->unsignedInteger('sanaei_inbound_id')->nullable();
            $table->string('sanaei_client_uuid')->nullable();
            $table->string('client_email')->nullable();
            $table->string('client_panel_password_hash')->nullable();
            $table->string('portal_token', 32)->unique();
            $table->unsignedBigInteger('data_limit_bytes')->nullable();
            $table->unsignedBigInteger('data_used_bytes')->default(0);
            $table->timestamp('expiry_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            $table->index(['owner_seller_id', 'status']);
            $table->index('owner_agent_id');
            $table->index(['server_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
