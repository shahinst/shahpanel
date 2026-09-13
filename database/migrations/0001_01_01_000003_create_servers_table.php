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
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location')->nullable();
            $table->string('type', 20);
            $table->string('host');
            $table->unsignedInteger('port');
            $table->text('username_enc')->nullable();
            $table->text('password_enc')->nullable();
            $table->text('api_token_enc')->nullable();
            $table->boolean('is_public')->default(false);
            $table->unsignedInteger('max_accounts')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_health_check_at')->nullable();
            $table->string('last_health_status')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
