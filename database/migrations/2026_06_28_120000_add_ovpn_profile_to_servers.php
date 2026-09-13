<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->string('ovpn_profile_path')->nullable()->after('notes');
            $table->string('ovpn_profile_original_name')->nullable()->after('ovpn_profile_path');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['ovpn_profile_path', 'ovpn_profile_original_name']);
        });
    }
};
