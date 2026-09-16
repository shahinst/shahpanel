<?php

namespace App\Services;

use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\Server;
use App\Models\ServerInterface;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * PPP profiles on MikroTik VPN servers (panel-managed).
 *
 * Each profile uses an IP pool + /ppp/profile (local gateway + remote pool).
 */
class MikrotikPppProfileService
{
    public function __construct(
        protected MikrotikService $mikrotik,
        protected MikrotikProfileService $profileService,
        protected MikrotikWireguardInterfaceService $subnetHelper,
    ) {}

    public function create(
        Server $server,
        string $name,
        string $subnetCidr,
        string $protocol = 'any',
        bool $useEncryption = false,
        ?string $poolName = null,
        ?int $speedLimitMbps = null,
    ): ServerInterface {
        $name = trim($name);
        $subnetCidr = $this->normalizeSubnetCidr($subnetCidr);
        $protocol = strtolower(trim($protocol));
        $poolName = trim((string) ($poolName ?? ''));
        $poolName = $poolName !== '' ? $poolName : $this->defaultPoolName($name);

        if ($name === '') {
            throw new InvalidArgumentException(__('servers.ppp_profile_name_required'));
        }

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,31}$/', $name)) {
            throw new InvalidArgumentException(__('servers.ppp_profile_name_invalid'));
        }

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,31}$/', $poolName)) {
            throw new InvalidArgumentException(__('servers.ppp_pool_name_invalid'));
        }

        if ($this->mikrotik->pppProfileExists($server, $name)) {
            throw new InvalidArgumentException(__('servers.ppp_profile_exists', ['name' => $name]));
        }

        $created = $this->mikrotik->createPppProfileWithPool(
            $server,
            $name,
            $subnetCidr,
            $poolName,
            $useEncryption,
        );

        $servicePorts = [];
        try {
            $servicePorts = $this->mikrotik->detectEnabledServicePorts($server);
        } catch (\Throwable) {
            // optional
        }

        $port = $protocol !== 'any' ? ($servicePorts[$protocol] ?? null) : null;
        $key = $this->profileService->pppRemoteKey($name);

        $profile = ServerInterface::query()->updateOrCreate(
            [
                'server_id' => $server->id,
                'remote_key' => $key,
            ],
            [
                'name' => $name,
                'category' => 'ppp',
                'protocol' => $protocol,
                'port' => $port,
                'is_enabled' => true,
                'meta' => [
                    'profile_type' => 'ppp',
                    'subnet' => $subnetCidr,
                    'local_address' => $created['gateway'],
                    'remote_address' => $poolName,
                    'pool_name' => $poolName,
                    'pool_ranges' => $created['ranges'],
                    'use_encryption' => $useEncryption,
                    'ports' => $servicePorts,
                    'secret_count' => 0,
                    'panel_account_count' => 0,
                    'speed_limit_mbps' => $speedLimitMbps,
                    'created_via_panel' => true,
                ],
                'synced_at' => now(),
            ]
        );

        if ($speedLimitMbps !== null && $speedLimitMbps > 0) {
            try {
                app(MikrotikQueueService::class)->applyInterfaceSpeedQueues($server, $profile);
            } catch (\Throwable $exception) {
                \Illuminate\Support\Facades\Log::warning('PPP speed queues failed on create', [
                    'server_id' => $server->id,
                    'profile' => $name,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $profile;
    }

    /**
     * Pick a PPP profile for new secret provisioning (least load, skip full profiles).
     *
     * @param  bool  $respectProfileKeyWhenFull  Keep assigned profile even when full (updates).
     */
    public function resolveProfile(
        Server $server,
        ServiceType $serviceType,
        ?string $profileKey = null,
        bool $respectProfileKeyWhenFull = false,
    ): ServerInterface {
        if ($profileKey !== null && $profileKey !== '') {
            $profile = ServerInterface::query()
                ->where('server_id', $server->id)
                ->where('remote_key', $profileKey)
                ->first();

            if ($profile !== null && $this->profileService->isPppProfile($profile)) {
                if ($respectProfileKeyWhenFull || ! $this->isProfileAtCapacity($server, $profile)) {
                    return $profile;
                }
            }
        }

        $candidates = $this->enabledPppProfiles($server, $serviceType);

        if ($candidates->isEmpty()) {
            throw new InvalidArgumentException(__('servers.ppp_no_profiles'));
        }

        $available = $candidates
            ->filter(fn (ServerInterface $row): bool => ! $this->isProfileAtCapacity($server, $row));

        if ($available->isEmpty()) {
            throw new InvalidArgumentException(__('servers.ppp_all_profiles_full', [
                'max' => $this->maxAccountsPerProfile(),
            ]));
        }

        return $available
            ->sortBy(fn (ServerInterface $row): array => $this->profileSortKey($server, $row))
            ->first();
    }

    /**
     * Pick least-loaded PPP profile from an allowed set (package multi-select).
     *
     * @param  list<string>  $profileKeys
     */
    public function resolveProfileFromAllowedKeys(Server $server, ServiceType $serviceType, array $profileKeys): ServerInterface
    {
        $profileKeys = array_values(array_unique(array_filter(
            array_map('strval', $profileKeys),
            static fn (string $key): bool => $key !== ''
        )));

        if ($profileKeys === []) {
            return $this->resolveProfile($server, $serviceType);
        }

        $keySet = array_flip($profileKeys);
        $candidates = $this->enabledPppProfiles($server, $serviceType)
            ->filter(fn (ServerInterface $row): bool => isset($keySet[(string) $row->remote_key]));

        if ($candidates->isEmpty()) {
            throw new InvalidArgumentException(__('servers.ppp_no_profiles'));
        }

        $available = $candidates
            ->filter(fn (ServerInterface $row): bool => ! $this->isProfileAtCapacity($server, $row));

        if ($available->isEmpty()) {
            throw new InvalidArgumentException(__('servers.ppp_all_profiles_full', [
                'max' => $this->maxAccountsPerProfile(),
            ]));
        }

        return $available
            ->sortBy(fn (ServerInterface $row): array => $this->profileSortKey($server, $row))
            ->first();
    }

    public function enrichSyncedProfile(Server $server, ServerInterface $iface, string $profileName): void
    {
        $meta = $iface->meta ?? [];
        $key = $this->profileService->pppRemoteKey($profileName);

        ServerInterface::query()
            ->where('server_id', $server->id)
            ->where('remote_key', $key)
            ->update([
                'meta' => array_merge($meta, [
                    'secret_count' => $this->mikrotik->countPppSecretsOnProfile($server, $profileName),
                    'panel_account_count' => $this->panelAccountCount($server, $key),
                ]),
            ]);
    }

    public function maxAccountsPerProfile(): int
    {
        return max(1, (int) config('shahpanel.wireguard.max_peers_per_interface', 250));
    }

    protected function isProfileAtCapacity(Server $server, ServerInterface $profile): bool
    {
        return $this->profileAccountLoad($server, $profile) >= $this->maxAccountsPerProfile();
    }

    protected function profileAccountLoad(Server $server, ServerInterface $profile): int
    {
        return $this->profileLoads($server, $profile)['total'];
    }

    /**
     * Sort key: fewer panel accounts first, then fewer router secrets, then name.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    protected function profileSortKey(Server $server, ServerInterface $profile): array
    {
        $loads = $this->profileLoads($server, $profile);

        return [$loads['panel'], $loads['router'], $profile->name];
    }

    /**
     * @return array{panel: int, router: int, total: int}
     */
    protected function profileLoads(Server $server, ServerInterface $profile): array
    {
        $panel = $this->panelAccountCount($server, (string) $profile->remote_key);
        $router = $this->liveRouterSecretCount($server, $profile);

        return [
            'panel' => $panel,
            'router' => $router,
            'total' => max($panel, $router),
        ];
    }

    protected function liveRouterSecretCount(Server $server, ServerInterface $profile): int
    {
        try {
            return $this->mikrotik->countPppSecretsOnProfile(
                $server,
                $this->profileService->pppProfileName($profile)
            );
        } catch (\Throwable) {
            return (int) ($profile->meta['secret_count'] ?? 0);
        }
    }

    public function countPanelAccounts(Server $server, string $profileKey): int
    {
        return $this->panelAccountCount($server, $profileKey);
    }

    protected function panelAccountCount(Server $server, string $profileKey): int
    {
        if (! Schema::hasColumn('accounts', 'mikrotik_profile_key')) {
            return 0;
        }

        return Account::query()
            ->where('server_id', $server->id)
            ->where('mikrotik_profile_key', $profileKey)
            ->whereNull('wireguard_public_key')
            ->count();
    }

    /**
     * @return \Illuminate\Support\Collection<int, ServerInterface>
     */
    protected function enabledPppProfiles(Server $server, ServiceType $serviceType)
    {
        $serviceName = $this->profileService->pppServiceForType($serviceType);

        return ServerInterface::query()
            ->where('server_id', $server->id)
            ->where('is_enabled', true)
            ->where(function ($query) {
                $query->where('category', 'ppp')
                    ->orWhere('remote_key', 'like', 'profile:ppp:%');
            })
            ->orderBy('name')
            ->get()
            ->filter(function (ServerInterface $profile) use ($serviceName): bool {
                if ($serviceName === 'any') {
                    return true;
                }

                $protocol = strtolower((string) ($profile->protocol ?? ''));

                if ($protocol === '' || $protocol === 'ppp' || $protocol === 'any') {
                    return true;
                }

                return $protocol === $serviceName;
            });
    }

    public function normalizeSubnetCidr(string $subnetCidr): string
    {
        return $this->subnetHelper->normalizeSubnetCidr($subnetCidr);
    }

    protected function defaultPoolName(string $profileName): string
    {
        return 'pool-'.$profileName;
    }
}
