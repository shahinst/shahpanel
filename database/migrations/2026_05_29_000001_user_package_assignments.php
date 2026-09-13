<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'package_id']);
            $table->index('package_id');
        });

        if (! Schema::hasTable('packages') || ! Schema::hasTable('users')) {
            return;
        }

        $packageIds = DB::table('packages')->where('is_active', true)->pluck('id');

        if ($packageIds->isEmpty()) {
            return;
        }

        $staffIds = DB::table('users')
            ->whereIn('role', ['admin', 'agent', 'seller'])
            ->pluck('id');

        $now = now();

        foreach ($staffIds as $userId) {
            foreach ($packageIds as $packageId) {
                DB::table('user_packages')->insertOrIgnore([
                    'user_id' => $userId,
                    'package_id' => $packageId,
                    'assigned_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_packages');
    }
};
