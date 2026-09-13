<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->boolean('l2tp_use_ipsec')->nullable()->after('ovpn_profile_original_name');
            $table->text('l2tp_ipsec_secret_enc')->nullable()->after('l2tp_use_ipsec');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['l2tp_use_ipsec', 'l2tp_ipsec_secret_enc']);
        });
    }
};
