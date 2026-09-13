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
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('service_type', 30);
            $table->foreignId('default_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->unsignedInteger('duration_days');
            $table->decimal('data_limit_gb', 10, 2)->nullable();
            $table->decimal('base_price', 18, 2);
            $table->decimal('default_agent_commission', 5, 4)->default(0);
            $table->decimal('default_seller_commission', 5, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['service_type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
