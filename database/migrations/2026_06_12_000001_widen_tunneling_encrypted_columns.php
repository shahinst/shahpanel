<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->widenTextColumnIfNeeded('managed_interfaces', 'private_key_enc');
        $this->widenTextColumnIfNeeded('tunnel_groups', 'ipsec_secret_enc');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->narrowTextColumnIfNeeded('managed_interfaces', 'private_key_enc');
        $this->narrowTextColumnIfNeeded('tunnel_groups', 'ipsec_secret_enc');
    }

    protected function widenTextColumnIfNeeded(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $dataType = $this->columnDataType($table, $column);

        if (in_array($dataType, ['text', 'mediumtext', 'longtext'], true)) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` TEXT NULL");
    }

    protected function narrowTextColumnIfNeeded(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $dataType = $this->columnDataType($table, $column);

        if ($dataType === 'varchar') {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR(255) NULL");
    }

    protected function columnDataType(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [Schema::getConnection()->getDatabaseName(), $table, $column]
        );

        return $row ? strtolower((string) $row->DATA_TYPE) : null;
    }
};
