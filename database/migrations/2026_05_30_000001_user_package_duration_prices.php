<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_package_duration_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_duration_id')->constrained()->cascadeOnDelete();
            $table->decimal('wholesale_price', 18, 2);
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'package_duration_id']);
        });

        if (! Schema::hasTable('user_packages')) {
            return;
        }

        $now = now();
        $rows = DB::table('user_packages')->get();

        foreach ($rows as $assignment) {
            $durations = DB::table('package_durations')
                ->where('package_id', $assignment->package_id)
                ->where('is_enabled', true)
                ->get(['id', 'price']);

            foreach ($durations as $duration) {
                DB::table('user_package_duration_prices')->updateOrInsert(
                    [
                        'user_id' => $assignment->user_id,
                        'package_duration_id' => $duration->id,
                    ],
                    [
                        'wholesale_price' => $duration->price,
                        'assigned_by_user_id' => $assignment->assigned_by_user_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_package_duration_prices');
    }
};
