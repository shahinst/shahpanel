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
        Schema::create('storefronts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('brand_name');
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 20)->nullable();
            $table->string('telegram_contact')->nullable();
            $table->string('phone_contact')->nullable();
            $table->text('description')->nullable();
            $table->string('custom_subdomain')->nullable();
            $table->string('slug')->unique();
            $table->boolean('is_published')->default(false);
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('storefronts');
    }
};
