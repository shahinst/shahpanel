<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('packages') || ! Schema::hasColumn('packages', 'pricing_model')) {
            return;
        }

        DB::table('packages')
            ->where('pricing_model', 'fixed')
            ->whereNotNull('min_data_gb')
            ->whereNotNull('max_data_gb')
            ->update(['pricing_model' => 'elastic']);
    }

    public function down(): void
    {
        // Non-reversible: cannot know which rows were auto-corrected.
    }
};
