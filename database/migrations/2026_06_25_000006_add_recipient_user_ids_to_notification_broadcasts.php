<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_broadcasts', function (Blueprint $table): void {
            if (! Schema::hasColumn('notification_broadcasts', 'recipient_user_ids')) {
                $table->json('recipient_user_ids')->nullable()->after('audience');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_broadcasts', function (Blueprint $table): void {
            if (Schema::hasColumn('notification_broadcasts', 'recipient_user_ids')) {
                $table->dropColumn('recipient_user_ids');
            }
        });
    }
};
