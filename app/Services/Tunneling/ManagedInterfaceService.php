<?php

namespace App\Services\Tunneling;

use App\Enums\DesiredObjectStatus;
use App\Jobs\Tunneling\ApplyServerObjectsJob;
use App\Models\DesiredNetworkObject;
use App\Models\IpPoolAllocation;
use App\Models\Location;
use App\Models\ManagedInterface;
use App\Models\Server;
use App\Models\TunnelGroupEvent;
use App\Services\MikrotikService;
use App\Support\TunnelJobDispatcher;
use RuntimeException;
use Throwable;

/**
 * Client-facing interfaces on the Iran entry router:
 *
 *   wireguard — /interface/wireguard + gateway /ip/address (wg-public, wg-tr…)
 *   ppp       — /ip/pool + /ppp/profile pair (ppp-public, ppp-tr…)
 *
 * Account peers/secrets attach to these by interface/profile name only — the
 * existing account system is not touched. Each interface gets a dedicated
 * subnet from the client IP pool, so location traffic can be steered into its
 * tunnel group by source subnet.
 */
class ManagedInterfaceService
{
    public function __construct(
        protected IpPoolService $ipPools,
        protected MikrotikService $mikrotik,
    ) {
    }

    public function create(
        Server $server,
        string $type,
        ?Location $location = null,
        ?int $tunnelGroupId = null,
        ?string $name = null,
        ?int $listenPort = null,
        bool $queueApply = true,
    ): ManagedInterface {
        if (! in_array($type, ['wireguard', 'ppp'], true)) {
            throw new RuntimeException(__('services.interface_type_invalid'));
        }

        $suffix = $location?->code ?: 'public';
        $name ??= ($type === 'wireguard' ? 'wg-' : 'ppp-').$suffix;

        $existing = ManagedInterface::query()
            ->where('server_id', $server->id)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            if ($this->existsOnRouter($server, $name, $type)) {
                throw new RuntimeException(__('services.interface_already_exists', ['name' => $name]));
            }

            $this->purgeOrphanInterface($existing);
        }

        $interface = ManagedInterface::create([
            'server_id' => $server->id,
            'location_id' => $location?->id,
            'tunnel_group_id' => $tunnelGroupId,
            'type' => $type,
            'name' => $name,
            'subnet' => '0.0.0.0/24',
            'listen_port' => $listenPort,
            'status' => 'pending',
        ]);

        $subnet = $this->ipPools->allocate($type === 'wireguard' ? 'wg_clients' : 'ppp_clients', $interface);
        $interface->subnet = $subnet;

        if ($type === 'wireguard') {
            $keys = $this->mikrotik->generateKeys();
            $interface->private_key_enc = $keys['private_key'];
            $interface->public_key = $keys['public_key'];
            $interface->listen_port ??= $this->pickListenPort($server);
        }

        $interface->save();

        $this->buildDesiredObjects($interface);

        TunnelGroupEvent::record('interface_created', "اینترفیس «{$name}» ({$subnet}) ساخته شد.", [
            'server_id' => $server->id,
        ]);

        if ($queueApply) {
            TunnelJobDispatcher::dispatch(new ApplyServerObjectsJob($server->id));
        }

        return $interface;
    }

    /**
     * Mark managed interfaces active/removing after a server apply pass.
     */
    public function finalizeForServer(int $serverId): void
    {
        foreach (ManagedInterface::query()->where('server_id', $serverId)->get() as $interface) {
            $remaining = DesiredNetworkObject::query()
                ->where('managed_interface_id', $interface->id)
                ->where('status', '!=', DesiredObjectStatus::Applied->value)
                ->count();

            if ($interface->status === 'pending' && $remaining === 0) {
                $interface->update(['status' => 'active']);
            }

            if ($interface->status === 'removing') {
                $left = DesiredNetworkObject::query()
                    ->where('managed_interface_id', $interface->id)
                    ->count();

                if ($left === 0) {
                    $interface->delete();
                }
            }
        }
    }

    /**
     * Queue removal of the interface's router objects, then delete the row
     * (DesiredStateApplier deletes objects after converging).
     */
    public function remove(ManagedInterface $interface): void
    {
        DesiredNetworkObject::query()
            ->where('managed_interface_id', $interface->id)
            ->update(['status' => DesiredObjectStatus::Removing->value]);

        $interface->update(['status' => 'removing']);

        TunnelJobDispatcher::dispatch(new ApplyServerObjectsJob($interface->server_id));
    }

    public function buildDesiredObjects(ManagedInterface $interface): void
    {
        $marker = fn (string $key): string => config('tunneling.marker_prefix', 'vpnl').":mi{$interface->id}:{$key}";

        $specs = $interface->type === 'wireguard'
            ? $this->wireguardSpecs($interface)
            : $this->pppSpecs($interface);

        foreach ($specs as $spec) {
            DesiredNetworkObject::query()->updateOrCreate(
                [
                    'server_id' => $interface->server_id,
                    'marker' => $marker($spec['key']),
                ],
                [
                    'managed_interface_id' => $interface->id,
                    'tunnel_group_id' => $interface->tunnel_group_id,
                    'object_type' => $spec['object_type'],
                    'menu' => $spec['menu'],
                    'payload' => $spec['payload'],
                    'status' => DesiredObjectStatus::Pending,
                ],
            );
        }
    }

    /**
     * @return list<array{object_type: string, menu: string, key: string, payload: array<string, mixed>}>
     */
    protected function wireguardSpecs(ManagedInterface $interface): array
    {
        return [
            [
                'object_type' => 'wireguard',
                'menu' => '/interface/wireguard',
                'key' => 'if',
                'payload' => [
                    'name' => $interface->name,
                    'listen-port' => (string) $interface->listen_port,
                    'private-key' => $interface->wireguardPrivateKey(),
                    'mtu' => (string) config('shahpanel.wireguard.mtu', 1380),
                ],
            ],
            [
                'object_type' => 'ip_address',
                'menu' => '/ip/address',
                'key' => 'ip',
                'payload' => [
                    'address' => $interface->gatewayCidr(),
                    'interface' => $interface->name,
                ],
            ],
        ];
    }

    /**
     * @return list<array{object_type: string, menu: string, key: string, payload: array<string, mixed>}>
     */
    protected function pppSpecs(ManagedInterface $interface): array
    {
        [$base, $prefix] = array_pad(explode('/', $interface->subnet, 2), 2, '24');
        $network = ip2long($base) & (~((1 << (32 - (int) $prefix)) - 1) & 0xFFFFFFFF);
        $size = 1 << (32 - (int) $prefix);
        $gateway = long2ip($network + 1);
        $rangeStart = long2ip($network + 2);
        $rangeEnd = long2ip($network + $size - 2);
        $poolName = 'vpnl-pool-'.$interface->name;

        return [
            [
                'object_type' => 'interface',
                'menu' => '/ip/pool',
                'key' => 'pool',
                'payload' => [
                    'name' => $poolName,
                    'ranges' => "{$rangeStart}-{$rangeEnd}",
                ],
            ],
            [
                'object_type' => 'interface',
                'menu' => '/ppp/profile',
                'key' => 'profile',
                'payload' => [
                    'name' => $interface->name,
                    'local-address' => $gateway,
                    'remote-address' => $poolName,
                    'change-tcp-mss' => 'yes',
                ],
            ],
        ];
    }

    protected function pickListenPort(Server $server): int
    {
        $used = ManagedInterface::query()
            ->where('server_id', $server->id)
            ->whereNotNull('listen_port')
            ->pluck('listen_port')
            ->all();

        $port = 51820;

        while (in_array($port, $used, true)) {
            $port++;
        }

        return $port;
    }

    protected function existsOnRouter(Server $server, string $name, string $type): bool
    {
        $menu = $type === 'wireguard' ? '/interface/wireguard' : '/ppp/profile';

        try {
            $rows = $this->mikrotik->queryRouter($server, $menu, ['name' => $name]);
        } catch (Throwable) {
            return false;
        }

        return $rows !== [];
    }

    protected function purgeOrphanInterface(ManagedInterface $interface): void
    {
        DesiredNetworkObject::query()
            ->where('managed_interface_id', $interface->id)
            ->delete();

        IpPoolAllocation::query()
            ->where('owner_type', $interface->getMorphClass())
            ->where('owner_id', $interface->id)
            ->delete();

        $interface->delete();
    }
}
