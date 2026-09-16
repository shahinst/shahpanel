<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\ServerInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * WireGuard interfaces on MikroTik VPN servers (panel-managed, not tunneling).
 *
 * Each interface has its own client subnet (from /ip/address on the router).
 * Peers are attached to an interface; IP allocation uses that interface's subnet.
 */
class MikrotikWireguardInterfaceService
{
    public function __construct(
        protected MikrotikService $mikrotik,
        protected MikrotikProfileService $profileService,
    ) {}

    /**
     * @return array{synced: int, lines: list<string>, errors: list<string>, remote_keys: list<string>}
     */
    public function sync(Server $server): array
    {
        $lines = [];
        $errors = [];
        $remoteKeys = [];
        $synced = 0;

        $addresses = [];

        try {
            $addresses = $this->indexIpAddressesByInterface($server);
        } catch (Throwable $exception) {
            $errors[] = 'خطا در خواندن آدرس‌های IP (ادامه بدون سابنت): '.$exception->getMessage();
        }

        try {
            $wgInterfaces = $this->mikrotik->listWireguardInterfaces($server);
            $interfaceAliases = $this->buildWireguardInterfaceAliasMapFromRows($wgInterfaces);
            $peerCounts = $this->indexPeerCountsByInterface($server, $interfaceAliases);

            foreach ($wgInterfaces as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                try {
                    $gateway = $addresses[$name] ?? null;

                    if ($gateway === null) {
                        try {
                            $gateway = $this->mikrotik->getInterfaceGatewayAddress($server, $name);
                        } catch (Throwable) {
                            $gateway = null;
                        }
                    }

                    $subnet = $gateway !== null ? $this->subnetFromGatewayCidr($gateway) : null;
                    $peerCount = $peerCounts[$name] ?? 0;
                    $key = $this->profileService->wireguardRemoteKey($name);
                    $panelPeerCount = $this->panelPeerCount($server, $key, $name, $subnet);
                    $remoteKeys[] = $key;

                    $existing = ServerInterface::query()
                        ->where('server_id', $server->id)
                        ->where('remote_key', $key)
                        ->first();

                    $meta = [
                        'subnet' => $subnet,
                        'gateway' => $gateway,
                        'public_key' => (string) ($row['public-key'] ?? ''),
                        'peer_count' => $peerCount,
                        'panel_peer_count' => $panelPeerCount,
                        'mtu' => isset($row['mtu']) ? (int) $row['mtu'] : null,
                    ];

                    if ($existing !== null) {
                        $existingMeta = $existing->meta ?? [];
                        if (isset($existingMeta['speed_limit_mbps']) && (int) $existingMeta['speed_limit_mbps'] > 0) {
                            $meta['speed_limit_mbps'] = (int) $existingMeta['speed_limit_mbps'];
                        }
                        if ($existingMeta['created_via_panel'] ?? false) {
                            $meta['created_via_panel'] = true;
                        }
                        if (isset($existingMeta['firewall_rules'])) {
                            $meta['firewall_rules'] = $existingMeta['firewall_rules'];
                        }
                    }

                    ServerInterface::query()->updateOrCreate(
                        [
                            'server_id' => $server->id,
                            'remote_key' => $key,
                        ],
                        [
                            'name' => $name,
                            'category' => 'wireguard',
                            'protocol' => 'wireguard',
                            'port' => isset($row['listen-port']) ? (int) $row['listen-port'] : null,
                            'is_enabled' => ! $this->isDisabled($row['disabled'] ?? null),
                            'meta' => $meta,
                            'synced_at' => now(),
                        ]
                    );

                    $synced++;
                    $subnetLabel = $subnet ?? '—';
                    $lines[] = "اینترفیس WireGuard «{$name}» — سابنت {$subnetLabel} — {$peerCount} peer روی روتر";
                } catch (Throwable $exception) {
                    $errors[] = "خطا در اینترفیس WireGuard «{$name}»: ".$exception->getMessage();
                }
            }
        } catch (Throwable $exception) {
            $errors[] = 'خطا در دریافت اینترفیس‌های WireGuard: '.$exception->getMessage();
        }

        if ($synced === 0 && $errors === []) {
            $errors[] = 'هیچ اینترفیس WireGuard روی روتر یافت نشد.';
        }

        return [
            'synced' => $synced,
            'lines' => $lines,
            'errors' => $errors,
            'remote_keys' => $remoteKeys,
        ];
    }

    public function create(Server $server, string $name, string $subnetCidr, ?int $listenPort = null, ?int $speedLimitMbps = null): ServerInterface
    {
        $name = trim($name);
        $subnetCidr = $this->normalizeSubnetCidr($subnetCidr);

        if ($name === '') {
            throw new InvalidArgumentException(__('servers.wireguard_interface_name_required'));
        }

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,31}$/', $name)) {
            throw new InvalidArgumentException(__('servers.wireguard_interface_name_invalid'));
        }

        if ($this->mikrotik->wireguardInterfaceExists($server, $name)) {
            throw new InvalidArgumentException(__('servers.wireguard_interface_exists', ['name' => $name]));
        }

        $created = $this->mikrotik->createWireguardInterface($server, $name, $subnetCidr, $listenPort);
        $key = $this->profileService->wireguardRemoteKey($name);

        $firewallResult = app(MikrotikWireguardFirewallService::class)->ensureClientRules($server, $name, $subnetCidr);

        $interface = ServerInterface::query()->updateOrCreate(
            [
                'server_id' => $server->id,
                'remote_key' => $key,
            ],
            [
                'name' => $name,
                'category' => 'wireguard',
                'protocol' => 'wireguard',
                'port' => $created['listen_port'],
                'is_enabled' => true,
                'meta' => [
                    'subnet' => $subnetCidr,
                    'gateway' => $created['gateway'],
                    'public_key' => $created['public_key'],
                    'peer_count' => 0,
                    'panel_peer_count' => 0,
                    'speed_limit_mbps' => $speedLimitMbps,
                    'created_via_panel' => true,
                    'firewall_rules' => $firewallResult,
                ],
                'synced_at' => now(),
            ]
        );

        if ($speedLimitMbps !== null && $speedLimitMbps > 0) {
            try {
                $queueCount = app(MikrotikQueueService::class)->applyInterfaceSpeedQueues($server, $interface);
                Log::info('WireGuard speed queues applied on create', [
                    'server_id' => $server->id,
                    'interface' => $name,
                    'queues' => $queueCount,
                ]);
            } catch (Throwable $exception) {
                Log::warning('WireGuard speed queues failed on create', [
                    'server_id' => $server->id,
                    'interface' => $name,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $interface;
    }

    public function updateManaged(
        Server $server,
        ServerInterface $iface,
        string $newName,
        ?int $speedLimitMbps,
        bool $isEnabled,
    ): array {
        if ($iface->server_id !== $server->id || ! $iface->isWireguardProfile()) {
            throw new InvalidArgumentException(__('servers.wireguard_interface_invalid'));
        }

        $newName = trim($newName);

        if ($newName === '') {
            throw new InvalidArgumentException(__('servers.wireguard_interface_name_required'));
        }

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,31}$/', $newName)) {
            throw new InvalidArgumentException(__('servers.wireguard_interface_name_invalid'));
        }

        $oldName = $iface->name;
        $oldKey = (string) $iface->remote_key;
        $meta = $iface->meta ?? [];
        $subnet = is_string($meta['subnet'] ?? null) ? trim($meta['subnet']) : '';
        $newKey = $this->profileService->wireguardRemoteKey($newName);

        $queueService = app(MikrotikQueueService::class);
        $firewallService = app(MikrotikWireguardFirewallService::class);

        if ($newName !== $oldName) {
            $this->mikrotik->renameWireguardInterface($server, $oldName, $newName);
            $queueService->removeInterfaceSpeedQueues($server, $oldName);
            $firewallService->removeClientRules($server, $oldName);
            $this->renamePanelReferences($server, $oldKey, $newKey);
        }

        $this->mikrotik->setWireguardInterfaceDisabled($server, $newName, ! $isEnabled);

        $queueService->removeInterfaceSpeedQueues($server, $newName);

        if ($speedLimitMbps !== null && $speedLimitMbps > 0) {
            $meta['speed_limit_mbps'] = $speedLimitMbps;
        } else {
            unset($meta['speed_limit_mbps']);
        }

        $iface->update([
            'name' => $newName,
            'remote_key' => $newKey,
            'is_enabled' => $isEnabled,
            'meta' => $meta,
        ]);

        $iface = $iface->fresh();
        $queuesApplied = 0;

        if ($speedLimitMbps !== null && $speedLimitMbps > 0) {
            $queuesApplied = $queueService->applyInterfaceSpeedQueues($server, $iface);
        }

        if ($newName !== $oldName && $subnet !== '') {
            try {
                $firewallService->ensureClientRules($server, $newName, $subnet);
            } catch (Throwable $exception) {
                Log::warning('WireGuard firewall re-apply after rename failed', [
                    'server_id' => $server->id,
                    'interface' => $newName,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'interface' => $iface,
            'queues_applied' => $queuesApplied,
            'speed_limit_mbps' => $speedLimitMbps,
        ];
    }

    protected function renamePanelReferences(Server $server, string $oldKey, string $newKey): void
    {
        if ($oldKey === $newKey) {
            return;
        }

        Account::query()
            ->where('server_id', $server->id)
            ->where('mikrotik_profile_key', $oldKey)
            ->update(['mikrotik_profile_key' => $newKey]);

        Package::query()
            ->whereNotNull('mikrotik_profile_keys')
            ->get()
            ->each(function (Package $package) use ($oldKey, $newKey): void {
                $keys = $package->mikrotikProfileKeys();

                if (! in_array($oldKey, $keys, true)) {
                    return;
                }

                $updated = array_values(array_unique(array_map(
                    static fn (string $key): string => $key === $oldKey ? $newKey : $key,
                    $keys
                )));

                $package->update(['mikrotik_profile_keys' => $updated]);
            });
    }

    /**
     * Pick an interface for new peer provisioning (least load, skip interfaces at capacity).
     *
     * @param  bool  $respectProfileKeyWhenFull  Keep profile key even when interface is full (existing peer updates).
     */
    public function resolveInterfaceName(
        Server $server,
        ?string $preferredName = null,
        ?string $profileKey = null,
        bool $respectProfileKeyWhenFull = false,
    ): string {
        if ($profileKey !== null && $profileKey !== '') {
            $fromKey = $this->interfaceNameFromProfileKey($profileKey);

            if ($fromKey !== null && $this->mikrotik->wireguardInterfaceExists($server, $fromKey)) {
                if ($respectProfileKeyWhenFull || ! $this->isInterfaceAtCapacity($server, $fromKey)) {
                    return $fromKey;
                }
            }
        }

        if ($preferredName !== null && $preferredName !== '') {
            if (! $this->mikrotik->wireguardInterfaceExists($server, $preferredName)) {
                throw new InvalidArgumentException(__('servers.wireguard_interface_missing', ['name' => $preferredName]));
            }

            if ($respectProfileKeyWhenFull || ! $this->isInterfaceAtCapacity($server, $preferredName)) {
                return $preferredName;
            }

            throw new InvalidArgumentException(__('servers.wireguard_interface_full', [
                'name' => $preferredName,
                'max' => $this->maxPeersPerInterface(),
            ]));
        }

        $candidates = $this->enabledWireguardInterfaces($server);

        if ($candidates->isNotEmpty()) {
            $available = $candidates
                ->filter(fn (ServerInterface $row): bool => ! $this->isInterfaceAtCapacity($server, $row->name, $row));

            if ($available->isEmpty()) {
                throw new InvalidArgumentException(__('servers.wireguard_all_interfaces_full', [
                    'max' => $this->maxPeersPerInterface(),
                ]));
            }

            $picked = $available
                ->sortBy(fn (ServerInterface $row): array => $this->interfacePeerSortKey($server, $row->name, $row))
                ->first();

            return $this->profileService->wireguardInterfaceName($picked);
        }

        $fallback = $this->mikrotik->resolveWireguardInterfaceName($server);

        if ($this->isInterfaceAtCapacity($server, $fallback)) {
            throw new InvalidArgumentException(__('servers.wireguard_all_interfaces_full', [
                'max' => $this->maxPeersPerInterface(),
            ]));
        }

        return $fallback;
    }

    /**
     * Pick least-loaded interface from an allowed set (package multi-select).
     *
     * @param  list<string>  $profileKeys
     */
    public function resolveInterfaceNameFromAllowedKeys(Server $server, array $profileKeys): string
    {
        $profileKeys = array_values(array_unique(array_filter(
            array_map('strval', $profileKeys),
            static fn (string $key): bool => $key !== ''
        )));

        if ($profileKeys === []) {
            return $this->resolveInterfaceName($server);
        }

        $keySet = array_flip($profileKeys);
        $candidates = ServerInterface::query()
            ->where('server_id', $server->id)
            ->whereIn('remote_key', $profileKeys)
            ->orderBy('name')
            ->get()
            ->filter(fn (ServerInterface $row): bool => isset($keySet[(string) $row->remote_key]));

        if ($candidates->isEmpty()) {
            throw new InvalidArgumentException(__('packages.mikrotik_profile_not_on_servers', ['name' => $profileKeys[0]]));
        }

        $available = $candidates
            ->filter(fn (ServerInterface $row): bool => ! $this->isInterfaceAtCapacity($server, $row->name, $row));

        if ($available->isEmpty()) {
            throw new InvalidArgumentException(__('servers.wireguard_all_interfaces_full', [
                'max' => $this->maxPeersPerInterface(),
            ]));
        }

        $picked = $available
            ->sortBy(fn (ServerInterface $row): array => $this->interfacePeerSortKey($server, $row->name, $row))
            ->first();

        return $this->profileService->wireguardInterfaceName($picked);
    }

    /**
     * Like resolveInterfaceNameFromAllowedKeys(), but picks uniformly at random among the
     * package's allowed (non-full) interfaces on this server instead of least-loaded first.
     * Used when (re)provisioning an account on a *different* server than it was created on
     * (server transfer) — the account must never reuse its old server's interface/address,
     * since pools and existing peers are per-server even when two routers share config.
     */
    public function randomAllowedInterfaceName(Server $server, array $profileKeys): string
    {
        $profileKeys = array_values(array_unique(array_filter(
            array_map('strval', $profileKeys),
            static fn (string $key): bool => $key !== ''
        )));

        if ($profileKeys === []) {
            return $this->resolveInterfaceName($server);
        }

        $keySet = array_flip($profileKeys);
        $candidates = ServerInterface::query()
            ->where('server_id', $server->id)
            ->whereIn('remote_key', $profileKeys)
            ->orderBy('name')
            ->get()
            ->filter(fn (ServerInterface $row): bool => isset($keySet[(string) $row->remote_key]));

        if ($candidates->isEmpty()) {
            throw new InvalidArgumentException(__('packages.mikrotik_profile_not_on_servers', ['name' => $profileKeys[0]]));
        }

        $available = $candidates
            ->filter(fn (ServerInterface $row): bool => ! $this->isInterfaceAtCapacity($server, $row->name, $row))
            ->values();

        if ($available->isEmpty()) {
            throw new InvalidArgumentException(__('servers.wireguard_all_interfaces_full', [
                'max' => $this->maxPeersPerInterface(),
            ]));
        }

        $picked = $available->random();

        return $this->profileService->wireguardInterfaceName($picked);
    }

    /**
     * Sort key: fewer panel peers first, then fewer router peers, then name.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    protected function interfacePeerSortKey(Server $server, string $interfaceName, ?ServerInterface $iface = null): array
    {
        $loads = $this->interfacePeerLoads($server, $interfaceName, $iface);

        return [$loads['panel'], $loads['router'], $interfaceName];
    }

    public function maxPeersPerInterface(): int
    {
        return max(1, (int) config('shahpanel.wireguard.max_peers_per_interface', 250));
    }

    protected function isInterfaceAtCapacity(Server $server, string $interfaceName, ?ServerInterface $iface = null): bool
    {
        return $this->interfacePeerLoad($server, $interfaceName, $iface) >= $this->maxPeersPerInterface();
    }

    protected function interfacePeerLoad(Server $server, string $interfaceName, ?ServerInterface $iface = null): int
    {
        return $this->interfacePeerLoads($server, $interfaceName, $iface)['total'];
    }

    /**
     * @return array{panel: int, router: int, total: int}
     */
    protected function interfacePeerLoads(Server $server, string $interfaceName, ?ServerInterface $iface = null): array
    {
        $iface ??= ServerInterface::query()
            ->where('server_id', $server->id)
            ->where('name', $interfaceName)
            ->first();

        $profileKey = $this->profileService->wireguardRemoteKey($interfaceName);
        $subnet = is_array($iface?->meta) ? ($iface->meta['subnet'] ?? null) : null;
        $panel = $this->panelPeerCount(
            $server,
            $profileKey,
            $interfaceName,
            is_string($subnet) ? $subnet : null
        );
        $router = $this->liveRouterPeerCount($server, $interfaceName, $iface);

        return [
            'panel' => $panel,
            'router' => $router,
            'total' => max($panel, $router),
        ];
    }

    protected function liveRouterPeerCount(Server $server, string $interfaceName, ?ServerInterface $iface = null): int
    {
        try {
            return $this->mikrotik->countWireguardPeersOnInterface($server, $interfaceName);
        } catch (Throwable) {
            return (int) ($iface?->meta['peer_count'] ?? 0);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, ServerInterface>
     */
    protected function enabledWireguardInterfaces(Server $server)
    {
        return ServerInterface::query()
            ->where('server_id', $server->id)
            ->where('is_enabled', true)
            ->where(function ($query) {
                $query->where('category', 'wireguard')
                    ->orWhere('remote_key', 'like', 'profile:wg:%')
                    ->orWhere('remote_key', 'like', 'wg:%');
            })
            ->orderBy('name')
            ->get();
    }

    public function resolveSubnet(Server $server, string $interfaceName): ?string
    {
        $key = $this->profileService->wireguardRemoteKey($interfaceName);

        $row = ServerInterface::query()
            ->where('server_id', $server->id)
            ->where('remote_key', $key)
            ->first();

        $subnet = $row?->meta['subnet'] ?? null;

        if (is_string($subnet) && $subnet !== '') {
            return $subnet;
        }

        $gateway = $this->mikrotik->getInterfaceGatewayAddress($server, $interfaceName);

        return $gateway !== null ? $this->subnetFromGatewayCidr($gateway) : null;
    }

    public function profileKeyForInterface(string $interfaceName): string
    {
        return $this->profileService->wireguardRemoteKey($interfaceName);
    }

    protected function panelPeerCount(Server $server, string $profileKey, string $interfaceName, ?string $subnet): int
    {
        $hasProfileKeyColumn = Schema::hasColumn('accounts', 'mikrotik_profile_key');
        $query = Account::query()
            ->where('server_id', $server->id)
            ->whereNotNull('wireguard_public_key');

        $accounts = $hasProfileKeyColumn
            ? $query->get(['mikrotik_profile_key', 'wireguard_address'])
            : $query->get(['wireguard_address']);

        $legacyKey = 'wg:'.$interfaceName;
        $singleInterface = $this->wireguardInterfaceCountInDb($server) === 1;
        $count = 0;

        foreach ($accounts as $account) {
            $storedKey = $hasProfileKeyColumn ? $account->mikrotik_profile_key : null;

            if ($storedKey === $profileKey || $storedKey === $legacyKey) {
                $count++;

                continue;
            }

            if ($storedKey !== null && $storedKey !== '') {
                continue;
            }

            if ($subnet !== null && $this->wireguardAddressInSubnet((string) $account->wireguard_address, $subnet)) {
                $count++;

                continue;
            }

            if ($subnet === null && $singleInterface) {
                $count++;
            }
        }

        return $count;
    }

    protected function wireguardInterfaceCountInDb(Server $server): int
    {
        return ServerInterface::query()
            ->where('server_id', $server->id)
            ->where(function ($query) {
                $query->where('category', 'wireguard')
                    ->orWhere('remote_key', 'like', 'profile:wg:%')
                    ->orWhere('remote_key', 'like', 'wg:%');
            })
            ->count();
    }

    protected function wireguardAddressInSubnet(string $address, string $subnetCidr): bool
    {
        $address = trim(explode('/', trim($address), 2)[0]);
        $ipLong = ip2long($address);

        if ($ipLong === false) {
            return false;
        }

        $subnetCidr = trim($subnetCidr);
        [$base, $prefixRaw] = array_pad(explode('/', $subnetCidr, 2), 2, '24');
        $prefix = (int) $prefixRaw;

        if ($prefix < 1 || $prefix > 32) {
            return false;
        }

        $baseLong = ip2long($base);
        if ($baseLong === false) {
            return false;
        }

        $size = 1 << (32 - $prefix);
        $network = $baseLong & (~($size - 1) & 0xFFFFFFFF);
        $ipUnsigned = $ipLong & 0xFFFFFFFF;

        return $ipUnsigned >= $network && $ipUnsigned < ($network + $size);
    }

    /**
     * @return array<string, int> interface name => peer count (enabled + disabled)
     */
    protected function indexPeerCountsByInterface(Server $server, array $interfaceAliases): array
    {
        $counts = [];

        foreach ($this->mikrotik->listWireguardPeers($server) as $peer) {
            $iface = $this->resolveWireguardInterfaceName(
                (string) ($peer['interface'] ?? ''),
                $interfaceAliases,
            );

            if ($iface === null) {
                continue;
            }

            $counts[$iface] = ($counts[$iface] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    protected function buildWireguardInterfaceAliasMapFromRows(array $rows): array
    {
        $aliases = [];

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $aliases[$name] = $name;

            $id = (string) ($row['.id'] ?? '');
            if ($id !== '') {
                $aliases[$id] = $name;
            }
        }

        return $aliases;
    }

    /**
     * @return array<string, string>
     */
    protected function buildWireguardInterfaceAliasMap(Server $server): array
    {
        return $this->buildWireguardInterfaceAliasMapFromRows(
            $this->mikrotik->listWireguardInterfaces($server),
        );
    }

    /**
     * @param  array<string, string>  $interfaceAliases
     */
    protected function resolveWireguardInterfaceName(string $reference, array $interfaceAliases): ?string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        if (isset($interfaceAliases[$reference])) {
            return $interfaceAliases[$reference];
        }

        if (str_starts_with($reference, '*')) {
            return $interfaceAliases[$reference] ?? null;
        }

        return $reference;
    }

    /**
     * @return array<string, string> interface name => gateway CIDR
     */
    protected function indexIpAddressesByInterface(Server $server): array
    {
        $map = [];

        foreach ($this->mikrotik->listIpAddresses($server) as $row) {
            $iface = (string) ($row['actual-interface'] ?? $row['interface'] ?? '');
            $address = (string) ($row['address'] ?? '');

            if ($iface === '' || $address === '') {
                continue;
            }

            if (! isset($map[$iface])) {
                $map[$iface] = $address;
            }
        }

        return $map;
    }

    protected function interfaceNameFromProfileKey(string $profileKey): ?string
    {
        if (preg_match('/^profile:wg:(.+)$/', $profileKey, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^wg:(.+)$/', $profileKey, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function subnetFromGatewayCidr(string $gatewayCidr): ?string
    {
        $gatewayCidr = trim($gatewayCidr);
        if ($gatewayCidr === '' || str_contains($gatewayCidr, ':')) {
            return null;
        }

        [$ip, $prefixRaw] = array_pad(explode('/', $gatewayCidr, 2), 2, '24');
        $prefix = (int) $prefixRaw;

        if ($prefix < 1 || $prefix > 32) {
            return null;
        }

        $long = ip2long($ip);
        if ($long === false) {
            return null;
        }

        $hostBits = 32 - $prefix;
        $size = 1 << $hostBits;
        $network = $long & (~($size - 1) & 0xFFFFFFFF);

        return long2ip($network).'/'.$prefix;
    }

    public function normalizeSubnetCidr(string $subnetCidr): string
    {
        $subnetCidr = trim($subnetCidr);

        if (! preg_match('/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/', $subnetCidr)) {
            throw new InvalidArgumentException(__('servers.wireguard_subnet_invalid'));
        }

        [$base, $prefixRaw] = explode('/', $subnetCidr, 2);
        $prefix = (int) $prefixRaw;

        if ($prefix < 8 || $prefix > 30) {
            throw new InvalidArgumentException(__('servers.wireguard_subnet_prefix_range'));
        }

        foreach (explode('.', $base) as $octet) {
            if ((int) $octet > 255) {
                throw new InvalidArgumentException(__('servers.wireguard_subnet_invalid'));
            }
        }

        $subnet = $this->subnetFromGatewayCidr(long2ip(ip2long($base) & (~((1 << (32 - $prefix)) - 1) & 0xFFFFFFFF)).'/'.$prefix);

        if ($subnet === null) {
            throw new InvalidArgumentException(__('servers.wireguard_subnet_invalid'));
        }

        return $subnet;
    }

    protected function isDisabled(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['true', 'yes', '1'], true);
    }
}
