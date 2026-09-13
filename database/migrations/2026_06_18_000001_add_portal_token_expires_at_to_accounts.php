<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounts', 'portal_token_expires_at')) {
                $table->timestamp('portal_token_expires_at')->nullable()->after('portal_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if (Schema::hasColumn('accounts', 'portal_token_expires_at')) {
                $table->dropColumn('portal_token_expires_at');
            }
        });
    }
};
