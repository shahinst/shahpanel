<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerInterface;
use Illuminate\Support\Facades\Log;
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
        $parentLimit = $this->parentQueueLimit();
        $applied = 0;

        try {
            if ($iface->isWireguardProfile()) {
                $this->mikrotik->ensureSimpleQueue($server, $name, $name, $parentLimit);
            } else {
                $this->mikrotik->ensureSimpleQueue($server, $name, $subnet, $parentLimit);
            }
            $applied++;
        } catch (Throwable $e) {
            Log::warning('MikroTik parent queue failed', [
                'server_id' => $server->id,
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
        }

        foreach ($this->hostOctetsFromSubnet($subnet) as $octet) {
            $queueName = "{$name}-{$octet}-{$speedMbps}";
            $target = $this->ipFromSubnetAndOctet($subnet, $octet);

            if ($target === null) {
                continue;
            }

            try {
                $this->mikrotik->ensureSimpleQueue($server, $queueName, $target, $peerLimit, $name);
                $applied++;
            } catch (Throwable $e) {
                Log::warning('MikroTik peer queue failed', [
                    'server_id' => $server->id,
                    'queue' => $queueName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $applied;
    }

    public function removeInterfaceSpeedQueues(Server $server, string $interfaceName): int
    {
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
