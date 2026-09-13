<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedSmallInteger('daily_server_change_limit')->nullable()->after('status');
        });

        Schema::create('account_server_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('old_server_id')->constrained('servers')->restrictOnDelete();
            $table->foreignId('new_server_id')->constrained('servers')->restrictOnDelete();
            $table->timestamps();

            $table->index(['changed_by_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_server_changes');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('daily_server_change_limit');
        });
    }
};
