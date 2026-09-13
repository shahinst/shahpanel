<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncUsageCommand extends Command
{
    protected $signature = 'sync:usage {--server= : Limit sync to a single server ID}';

    protected $description = 'Sync traffic usage for all active VPN accounts';

    public function handle(SyncService $syncService): int
    {
        $query = Server::query()->active();

        if ($serverId = $this->option('server')) {
            $query->whereKey($serverId);
        }

        $servers = $query->get();

        if ($servers->isEmpty()) {
            $this->warn('No active servers found.');

            return self::SUCCESS;
        }

        foreach ($servers as $server) {
            $this->info("Syncing server #{$server->id} ({$server->name})...");

            $log = $syncService->syncServer($server);

            $this->line("  Status: {$log->status->value}, synced: {$log->accounts_synced}, errors: {$log->errors_count}");
        }

        Cache::put('system_health.job.sync_usage_at', now()->timestamp, now()->addHours(6));

        return self::SUCCESS;
    }
}
