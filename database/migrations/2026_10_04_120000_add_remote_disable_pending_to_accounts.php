<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers accounts whose server could not be reached when they expired, so
 * the expiry check keeps trying to switch them off instead of leaving a user
 * who is "expired" in the panel still connected on the server.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('accounts', 'remote_disable_pending_at')) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table): void {
            $table->timestamp('remote_disable_pending_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('accounts', 'remote_disable_pending_at')) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropIndex(['remote_disable_pending_at']);
            $table->dropColumn('remote_disable_pending_at');
        });
    }
};
