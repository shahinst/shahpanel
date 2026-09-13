<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_interfaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('remote_key');
            $table->string('name');
            $table->string('category', 32);
            $table->string('protocol')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->json('meta')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'remote_key']);
            $table->index(['server_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_interfaces');
    }
};
