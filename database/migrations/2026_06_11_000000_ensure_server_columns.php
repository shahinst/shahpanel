<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * servers.public_ip and servers.role were originally added by legacy tunnel
 * migrations (now deleted). Both columns are still used outside tunneling
 * (apiConnectionHost) and by the new tunneling system, so make sure they exist
 * on fresh installs too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('servers')) {
            return;
        }

        Schema::table('servers', function (Blueprint $table): void {
            if (! Schema::hasColumn('servers', 'public_ip')) {
                $table->string('public_ip', 45)->nullable()->after('host');
            }

            if (! Schema::hasColumn('servers', 'role')) {
                $table->string('role', 16)->default('internal')->after('type');
            }
        });
    }

    public function down(): void
    {
        // Intentionally left empty: these columns are shared state.
    }
};
