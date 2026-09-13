<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\Package;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ServerSelectionService
{
    public function __construct(
        protected MikrotikService $mikrotikService,
    ) {}

    public function pickLeastBusy(): Server
    {
        $servers = Server::query()
            ->active()
            ->withCount([
                'accounts as active_accounts_count' => fn ($query) => $query->whereIn('status', [
                    AccountStatus::Active,
                    AccountStatus::Disabled,
                    AccountStatus::Pending,
                ]),
            ])
            ->orderBy('active_accounts_count')
            ->orderBy('id')
            ->get();

        return $this->pickFromCollection($servers);
    }

    public function pickLeastBusyForPackage(Package $package): Server
    {
        $package->loadMissing('servers');

        $servers = $package->servers()
            ->active()
            ->withCount([
                'accounts as active_accounts_count' => fn ($query) => $query->whereIn('status', [
                    AccountStatus::Active,
                    AccountStatus::Disabled,
                    AccountStatus::Pending,
                ]),
            ])
            ->orderBy('active_accounts_count')
            ->orderBy('id')
            ->get();

        if ($servers->isEmpty()) {
            throw new RuntimeException(__('packages.servers_required'));
        }

        return $this->pickFromCollection($servers);
    }

    /**
     * @param  Collection<int, Server>  $servers
     */
    protected function pickFromCollection(Collection $servers): Server
    {
        $available = $servers->filter(function (Server $server): bool {
            if ($server->max_accounts === null || $server->max_accounts <= 0) {
                return true;
            }

            return $server->active_accounts_count < $server->max_accounts;
        });

        if ($available->isEmpty()) {
            throw new RuntimeException('هیچ سرور فعالی با ظرفیت خالی یافت نشد.');
        }

        $reachable = $available->filter(fn (Server $server): bool => $this->isServerReachable($server));

        if ($reachable->isEmpty()) {
            throw new RuntimeException(__('packages.no_reachable_servers'));
        }

        return $reachable->first();
    }

    protected function isServerReachable(Server $server): bool
    {
        if (! $server->isMikrotik()) {
            return true;
        }

        $cacheKey = 'server.reachable.mikrotik.'.$server->id;
        $ttl = max(30, (int) config('vpnpanel.mikrotik.reachability_cache_seconds', 90));

        return (bool) Cache::remember($cacheKey, $ttl, function () use ($server): bool {
            $ok = $this->mikrotikService->probeConnection($server, timeoutSeconds: 3);

            if (! $ok) {
                Log::warning('Skipping unreachable MikroTik server during selection', [
                    'server_id' => $server->id,
                    'host' => $server->apiConnectionHost(),
                    'port' => $server->port,
                ]);
            }

            return $ok;
        });
    }
}
