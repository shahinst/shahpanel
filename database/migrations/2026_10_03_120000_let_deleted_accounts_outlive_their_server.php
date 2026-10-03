<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a server answered 500 whenever it had ever held accounts that were
 * later deleted: accounts.server_id restricted the delete, and the soft-deleted
 * rows (kept for invoices and reports) still pointed at the server. The column
 * may now be empty, and deleting a server clears it on those rows. Live
 * accounts still block the delete in ServerController before it gets here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounts', 'server_id')) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropForeign(['server_id']);
        });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('server_id')->nullable()->change();
            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('accounts')->whereNull('server_id')->exists()) {
            // Rows already lost their server; they cannot go back to NOT NULL.
            return;
        }

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropForeign(['server_id']);
        });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('server_id')->nullable(false)->change();
            $table->foreign('server_id')->references('id')->on('servers')->restrictOnDelete();
        });
    }
};
