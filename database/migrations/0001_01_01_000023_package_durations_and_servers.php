<?php

use App\Enums\PackageDurationTier;
use App\Models\Package;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_durations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->string('tier', 10);
            $table->decimal('price', 18, 2)->default(0);
            $table->boolean('is_enabled')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['package_id', 'tier']);
        });

        Schema::create('package_server', function (Blueprint $table): void {
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();

            $table->primary(['package_id', 'server_id']);
        });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->foreignId('package_duration_id')
                ->nullable()
                ->after('package_id')
                ->constrained('package_durations')
                ->nullOnDelete();
        });

        $this->migrateExistingPackages();
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('package_duration_id');
        });

        Schema::dropIfExists('package_server');
        Schema::dropIfExists('package_durations');
    }

    protected function migrateExistingPackages(): void
    {
        if (! Schema::hasTable('packages')) {
            return;
        }

        $now = now();

        foreach (Package::query()->get() as $package) {
            $enabledTier = PackageDurationTier::OneMonth;
            $price = (string) ($package->base_price ?? 0);
            $sort = 0;

            foreach (PackageDurationTier::cases() as $tier) {
                $isEnabled = $tier === $enabledTier && (float) $price >= 0;

                DB::table('package_durations')->insert([
                    'package_id' => $package->id,
                    'tier' => $tier->value,
                    'price' => $tier === $enabledTier ? $price : 0,
                    'is_enabled' => $isEnabled,
                    'sort_order' => $sort++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($package->default_server_id) {
                DB::table('package_server')->insert([
                    'package_id' => $package->id,
                    'server_id' => $package->default_server_id,
                ]);
            }
        }
    }
};
