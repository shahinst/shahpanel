<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('packages', 'mikrotik_profile_keys')) {
            Schema::table('packages', function (Blueprint $table): void {
                $table->json('mikrotik_profile_keys')->nullable()->after('default_server_id');
            });
        }

        if (Schema::hasColumn('packages', 'mikrotik_profile_key')) {
            DB::table('packages')
                ->whereNotNull('mikrotik_profile_key')
                ->orderBy('id')
                ->each(function (object $row): void {
                    $key = trim((string) $row->mikrotik_profile_key);
                    if ($key === '') {
                        return;
                    }

                    DB::table('packages')
                        ->where('id', $row->id)
                        ->update(['mikrotik_profile_keys' => json_encode([$key])]);
                });

            Schema::table('packages', function (Blueprint $table): void {
                $table->dropColumn('mikrotik_profile_key');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('packages', 'mikrotik_profile_key')) {
            Schema::table('packages', function (Blueprint $table): void {
                $table->string('mikrotik_profile_key', 128)->nullable()->after('default_server_id');
            });
        }

        if (Schema::hasColumn('packages', 'mikrotik_profile_keys')) {
            DB::table('packages')
                ->whereNotNull('mikrotik_profile_keys')
                ->orderBy('id')
                ->each(function (object $row): void {
                    $decoded = json_decode((string) $row->mikrotik_profile_keys, true);
                    $first = is_array($decoded) ? ($decoded[0] ?? null) : null;

                    if (! is_string($first) || $first === '') {
                        return;
                    }

                    DB::table('packages')
                        ->where('id', $row->id)
                        ->update(['mikrotik_profile_key' => $first]);
                });

            Schema::table('packages', function (Blueprint $table): void {
                $table->dropColumn('mikrotik_profile_keys');
            });
        }
    }
};
