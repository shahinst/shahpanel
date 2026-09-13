<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'remnawave_squads')) {
                $table->json('remnawave_squads')->nullable()->after('pasarguard_groups_synced_at');
            }
            if (! Schema::hasColumn('servers', 'remnawave_squads_synced_at')) {
                $table->timestamp('remnawave_squads_synced_at')->nullable()->after('remnawave_squads');
            }
            if (! Schema::hasColumn('servers', 'remnawave_api_key_enc')) {
                $table->text('remnawave_api_key_enc')->nullable()->after('api_token_enc');
            }
        });

        Schema::table('packages', function (Blueprint $table) {
            if (! Schema::hasColumn('packages', 'remnawave_squads')) {
                $table->json('remnawave_squads')->nullable()->after('pasarguard_expiry_activation');
            }
            if (! Schema::hasColumn('packages', 'remnawave_traffic_strategy')) {
                $table->string('remnawave_traffic_strategy', 16)->default('NO_RESET')->after('remnawave_squads');
            }
        });

        Schema::table('accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts', 'remnawave_uuid')) {
                $table->string('remnawave_uuid', 64)->nullable()->after('pasarguard_subscription_url');
            }
            if (! Schema::hasColumn('accounts', 'remnawave_subscription_url')) {
                $table->string('remnawave_subscription_url', 512)->nullable()->after('remnawave_uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['remnawave_uuid', 'remnawave_subscription_url']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['remnawave_squads', 'remnawave_traffic_strategy']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['remnawave_squads', 'remnawave_squads_synced_at', 'remnawave_api_key_enc']);
        });
    }
};
