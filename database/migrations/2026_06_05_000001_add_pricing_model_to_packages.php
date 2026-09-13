<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('packages', 'pricing_model')) {
                $table->string('pricing_model', 20)->default('fixed')->after('service_type');
            }
            if (! Schema::hasColumn('packages', 'min_data_gb')) {
                $table->decimal('min_data_gb', 10, 2)->nullable()->after('data_limit_gb');
            }
            if (! Schema::hasColumn('packages', 'max_data_gb')) {
                $table->decimal('max_data_gb', 10, 2)->nullable()->after('min_data_gb');
            }
        });

        Schema::table('accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounts', 'purchased_data_gb')) {
                $table->decimal('purchased_data_gb', 10, 2)->nullable()->after('data_limit_bytes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            foreach (['pricing_model', 'min_data_gb', 'max_data_gb'] as $column) {
                if (Schema::hasColumn('packages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('accounts', function (Blueprint $table): void {
            if (Schema::hasColumn('accounts', 'purchased_data_gb')) {
                $table->dropColumn('purchased_data_gb');
            }
        });
    }
};
