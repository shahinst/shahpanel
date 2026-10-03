<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the queries that scan whole tables on a large panel: the expiry
 * check (status + expiry_at), the account lists filtered by type, the
 * subscription refresh picker, the money reports (transactions by type and
 * date) and the log pruning (activity_logs, server_sync_logs by date).
 *
 * Each index is added only when its columns are not already indexed in that
 * order, so panels that added one by hand do not fail here.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> */
    protected array $indexes = [
        'accounts' => [
            ['status', 'expiry_at'],
            ['service_type', 'created_at'],
            ['subscription_cached_at'],
        ],
        'transactions' => [
            ['type', 'created_at'],
            ['created_at'],
        ],
        'activity_logs' => [
            ['created_at'],
        ],
        'server_sync_logs' => [
            ['started_at'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $columns) {
                if (! $this->columnsExist($table, $columns) || $this->hasIndex($table, $columns)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                    $blueprint->index($columns);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $columns) {
                $name = $table.'_'.implode('_', $columns).'_index';

                if (in_array($name, array_column(Schema::getIndexes($table), 'name'), true)) {
                    Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                        $blueprint->dropIndex($name);
                    });
                }
            }
        }
    }

    /**
     * @param  list<string>  $columns
     */
    protected function hasIndex(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (array_slice($index['columns'], 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $columns
     */
    protected function columnsExist(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
};
