<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounts', 'client_portal_password_enc')) {
                $table->text('client_portal_password_enc')->nullable()->after('client_panel_password_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if (Schema::hasColumn('accounts', 'client_portal_password_enc')) {
                $table->dropColumn('client_portal_password_enc');
            }
        });
    }
};
