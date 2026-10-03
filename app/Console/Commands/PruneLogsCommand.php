<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Housekeeping rows (request logs, sync run logs, read notifications, failed
 * jobs) only ever grew, and on a busy panel they ended up larger than
 * everything else together, slowing backups and the pages that list them.
 *
 * Request logs are the activity rows with no entity; the entity rows
 * (account.renewed, account.refunded, ...) are read by renewal, refund and
 * gift logic and are kept.
 */
class PruneLogsCommand extends Command
{
    protected $signature = 'panel:prune-logs';

    protected $description = 'Delete old request logs, sync logs, read notifications and failed jobs';

    protected const CHUNK = 5000;

    public function handle(): int
    {
        $retention = (array) config('shahpanel.retention', []);

        $this->prune('activity_logs', 'created_at', (int) ($retention['request_logs_days'] ?? 0),
            fn (Builder $query) => $query->whereNull('entity_type'));
        $this->prune('server_sync_logs', 'started_at', (int) ($retention['sync_logs_days'] ?? 0));
        $this->prune('notifications', 'created_at', (int) ($retention['read_notifications_days'] ?? 0),
            fn (Builder $query) => $query->where('is_read', true));
        $this->prune('failed_jobs', 'failed_at', (int) ($retention['failed_jobs_days'] ?? 0));

        return self::SUCCESS;
    }

    protected function prune(string $table, string $column, int $days, ?callable $scope = null): void
    {
        if ($days <= 0 || ! Schema::hasTable($table)) {
            return;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        // Small batches keep each DELETE short, so sync and the web pages are
        // never stuck behind one long lock on a big table.
        do {
            $query = DB::table($table)->where($column, '<', $cutoff);

            if ($scope !== null) {
                $scope($query);
            }

            $ids = $query->orderBy('id')->limit(self::CHUNK)->pluck('id');

            if ($ids->isNotEmpty()) {
                $deleted += DB::table($table)->whereIn('id', $ids->all())->delete();
            }
        } while ($ids->count() === self::CHUNK);

        $this->line("{$table}: {$deleted} row(s) older than {$days} days removed");
    }
}
