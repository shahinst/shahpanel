<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerInterface;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * MikroTik /queue/simple speed limits for WG interfaces and PPP profiles.
 */
class MikrotikQueueService
{
    public function __construct(
        protected MikrotikService $mikrotik,
    ) {}

    public function speedLimitMbpsFromMeta(array $meta): ?int
    {
        $raw = $meta['speed_limit_mbps'] ?? null;

        if ($raw === null || $raw === '' || (int) $raw <= 0) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * Create parent + per-host simple queues for a subnet (client .2 … last-2).
     *
     * @return int queues created or updated
     */
    public function applyInterfaceSpeedQueues(Server $server, ServerInterface $iface): int
    {
        $meta = $iface->meta ?? [];
        $speedMbps = $this->speedLimitMbpsFromMeta($meta);

        if ($speedMbps === null) {
            return 0;
        }

        $subnet = is_string($meta['subnet'] ?? null) ? trim($meta['subnet']) : '';
        $name = $iface->name;

        if ($subnet === '' || $name === '') {
            return 0;
        }

        $peerLimit = $this->formatLimit($speedMbps);
        $desired = [[
            'name' => $name,
            'target' => $iface->isWireguardProfile() ? $name : $subnet,
            'max-limit' => $this->parentQueueLimit(),
        ]];

        foreach ($this->hostOctetsFromSubnet($subnet) as $octet) {
            $target = $this->ipFromSubnetAndOctet($subnet, $octet);

            if ($target !== null) {
                $desired[] = [
                    'name' => "{$name}-{$octet}-{$speedMbps}",
                    'target' => $target,
                    'max-limit' => $peerLimit,
                    'parent' => $name,
                ];
            }
        }

        $result = $this->mikrotik->syncInterfaceQueues($server, $name, $desired);

        // A partial apply used to be logged and reported as success, so the
        // admin saw "saved" while part of the subnet ran unlimited. Every
        // queue was still attempted; the failures are now the admin's to see.
        if ($result['errors'] !== []) {
            Log::warning('MikroTik speed queues partly failed', [
                'server_id' => $server->id,
                'interface' => $name,
                'errors' => array_slice($result['errors'], 0, 20),
            ]);

            throw new RuntimeException(__('servers.speed_queues_partial', [
                'failed' => count($result['errors']),
                'total' => count($desired),
                'error' => $result['errors'][0],
            ]));
        }

        $this->lastReport = [
            'fasttrack' => $this->mikrotik->exemptFromFasttrack($server, $name, $subnet),
            'verified' => $this->mikrotik->countInterfaceQueues($server, $name, $peerLimit),
            'expected' => count($desired) - 1,
        ];

        return count($desired);
    }

    /**
     * What the last applyInterfaceSpeedQueues() found on the router: whether
     * FastTrack had to be worked around, and how many address queues really
     * carry the new speed.
     *
     * @var array{fasttrack: bool, verified: array{total: int, at_speed: int}, expected: int}|null
     */
    public ?array $lastReport = null;

    public function removeInterfaceSpeedQueues(Server $server, string $interfaceName): int
    {
        rescue(fn () => $this->mikrotik->removeFasttrackExemption($server, $interfaceName), 0, false);

        return $this->mikrotik->removeWireguardInterfaceQueues($server, $interfaceName);
    }

    /**
     * Apply speed-limit queues for every WG/PPP profile on a server that has speed_limit_mbps in meta.
     *
     * @return array{applied: int, lines: list<string>, errors: list<string>}
     */
    public function applySpeedQueuesForServer(Server $server): array
    {
        $lines = [];
        $errors = [];
        $applied = 0;

        $ifaces = ServerInterface::query()
            ->where('server_id', $server->id)
            ->whereIn('category', ['wireguard', 'ppp'])
            ->orderBy('name')
            ->get();

        foreach ($ifaces as $iface) {
            $speedMbps = $this->speedLimitMbpsFromMeta($iface->meta ?? []);

            if ($speedMbps === null) {
                continue;
            }

            try {
                $count = $this->applyInterfaceSpeedQueues($server, $iface);

                if ($count > 0) {
                    $applied++;
                    $lines[] = __('servers.speed_queues_applied', [
                        'name' => $iface->name,
                        'count' => persian_digits($count),
                        'speed' => persian_digits($speedMbps),
                    ]);
                }
            } catch (Throwable $exception) {
                $errors[] = __('servers.speed_queues_failed', [
                    'name' => $iface->name,
                    'error' => $exception->getMessage(),
                ]);
                Log::warning('MikroTik speed queues apply failed', [
                    'server_id' => $server->id,
                    'interface' => $iface->name,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'applied' => $applied,
            'lines' => $lines,
            'errors' => $errors,
        ];
    }

    public function ensurePeerQueue(Server $server, string $interfaceName, string $addressCidr, ?int $speedMbps): void
    {
        if ($speedMbps === null || $speedMbps <= 0) {
            return;
        }

        $ip = trim(explode('/', trim($addressCidr), 2)[0]);
        $octet = (int) substr($ip, strrpos($ip, '.') + 1);

        if ($octet < 1 || $octet > 254) {
            return;
        }

        $queueName = "{$interfaceName}-{$octet}-{$speedMbps}";
        $limit = $this->formatLimit($speedMbps);
        $target = str_contains($addressCidr, '/') ? $addressCidr : "{$ip}/32";

        $this->mikrotik->ensureSimpleQueue($server, $queueName, $target, $limit, $interfaceName);
    }

    public function formatLimit(int $speedMbps): string
    {
        return "{$speedMbps}M/{$speedMbps}M";
    }

    public function parentQueueLimit(): string
    {
        $limit = trim((string) config('shahpanel.wireguard.parent_queue_limit', '500M/500M'));

        return $limit !== '' ? $limit : '500M/500M';
    }

    /**
     * @return list<int>
     */
    protected function hostOctetsFromSubnet(string $subnetCidr): array
    {
        [$base, $prefixRaw] = array_pad(explode('/', trim($subnetCidr), 2), 2, '24');
        $prefix = (int) $prefixRaw;
        $size = 1 << (32 - $prefix);
        $network = ip2long($base) & (~($size - 1) & 0xFFFFFFFF);
        $first = $network + 2;
        $last = $network + $size - 2;
        $octets = [];

        for ($ip = $first; $ip <= $last; $ip++) {
            $octets[] = $ip & 0xFF;
        }

        return $octets;
    }

    protected function ipFromSubnetAndOctet(string $subnetCidr, int $octet): ?string
    {
        [$base, $prefixRaw] = array_pad(explode('/', trim($subnetCidr), 2), 2, '24');
        $prefix = (int) $prefixRaw;
        $size = 1 << (32 - $prefix);
        $network = ip2long($base) & (~($size - 1) & 0xFFFFFFFF);
        $first = $network + 2;
        $last = $network + $size - 2;

        for ($ip = $first; $ip <= $last; $ip++) {
            if (($ip & 0xFF) === $octet) {
                return long2ip($ip).'/32';
            }
        }

        return null;
    }
}
