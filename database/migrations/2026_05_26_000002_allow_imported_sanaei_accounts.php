<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE accounts DROP FOREIGN KEY accounts_package_id_foreign');
            DB::statement('ALTER TABLE accounts MODIFY package_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE accounts ADD CONSTRAINT accounts_package_id_foreign FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE RESTRICT');
        } else {
            Schema::table('accounts', function (Blueprint $table) {
                $table->foreignId('package_id')->nullable()->change();
            });
        }

        Schema::table('accounts', function (Blueprint $table) {
            if (! $this->indexExists('accounts', 'accounts_server_sanaei_uuid_unique')) {
                $table->unique(['server_id', 'sanaei_client_uuid'], 'accounts_server_sanaei_uuid_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique('accounts_server_sanaei_uuid_unique');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE accounts DROP FOREIGN KEY accounts_package_id_foreign');
            DB::statement('ALTER TABLE accounts MODIFY package_id BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE accounts ADD CONSTRAINT accounts_package_id_foreign FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE RESTRICT');
        }
    }

    protected function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        $result = DB::select(
            'SELECT COUNT(*) AS aggregate FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $index]
        );

        return (int) ($result[0]->aggregate ?? 0) > 0;
    }
};
