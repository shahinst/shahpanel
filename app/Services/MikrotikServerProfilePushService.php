<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerInterface;
use App\Services\RouterOs\DesiredStateApplier;
use App\Services\Tunneling\ManagedInterfaceService;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Push cached MikroTik profiles/interfaces from the panel DB back to the router,
 * then apply desired firewall / routing / address-list objects.
 *
 * Safe-by-default: only creates missing objects; never deletes router config.
 */
class MikrotikServerProfilePushService
{
    public function __construct(
        protected MikrotikService $mikrotik,
        protected DesiredStateApplier $desiredStateApplier,
        protected ManagedInterfaceService $managedInterfaces,
    ) {}

    /**
     * @return array{restored: int, lines: list<string>, errors: list<string>}
     */
    public function push(Server $server): array
    {
        $lines = [];
        $errors = [];
        $restored = 0;

        $interfaces = ServerInterface::query()
            ->where('server_id', $server->id)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        foreach ($interfaces as $iface) {
            try {
                if ($iface->isWireguardProfile()) {
                    $result = $this->pushWireguardInterface($server, $iface);
                } elseif ($iface->isPppProfile()) {
                    $result = $this->pushPppProfile($server, $iface);
                } else {
                    continue;
                }

                $lines[] = $result['message'];

                if ($result['action'] === 'created') {
                    $restored++;
                }
            } catch (Throwable $exception) {
                $errors[] = "{$iface->name}: ".$exception->getMessage();
            }
        }

        try {
            $stats = $this->desiredStateApplier->applyForServer($server);
            $lines[] = __('servers.profile_push_network_objects', [
                'created' => $stats['created'],
                'updated' => $stats['updated'],
                'unchanged' => $stats['unchanged'],
                'failed' => $stats['failed'],
            ]);
            $this->managedInterfaces->finalizeForServer($server->id);
        } catch (Throwable $exception) {
            $errors[] = __('servers.profile_push_network_failed', ['message' => $exception->getMessage()]);
        }

        if ($interfaces->isEmpty()) {
            $errors[] = __('servers.profile_push_empty_cache');
        }

        return compact('restored', 'lines', 'errors');
    }

    /**
     * @return array{action: string, message: string}
     */
    protected function pushWireguardInterface(Server $server, ServerInterface $iface): array
    {
        $name = $iface->name;
        $meta = $iface->meta ?? [];
        $subnet = is_string($meta['subnet'] ?? null) ? trim($meta['subnet']) : '';
        $gateway = is_string($meta['gateway'] ?? null) ? trim($meta['gateway']) : '';

        if (! $this->mikrotik->wireguardInterfaceExists($server, $name)) {
            if ($subnet === '') {
                throw new RuntimeException(__('servers.profile_push_wg_no_subnet', ['name' => $name]));
            }

            $this->mikrotik->createWireguardInterface(
                $server,
                $name,
                $subnet,
                $iface->port,
            );

            $this->ensureWireguardFirewallRules($server, $name, $subnet);

            return [
                'action' => 'created',
                'message' => __('servers.profile_push_wg_created', ['name' => $name]),
            ];
        }

        $address = $gateway !== '' ? $gateway : ($subnet !== '' ? $this->mikrotik->gatewayCidrFromSubnet($subnet) : null);

        if ($address !== null && ! $this->mikrotik->hasIpAddressOnInterface($server, $name, $address)) {
            $this->mikrotik->ensureIpAddress($server, $address, $name);

            $this->ensureWireguardFirewallRules($server, $name, $subnet);

            return [
                'action' => 'created',
                'message' => __('servers.profile_push_wg_address_restored', ['name' => $name, 'address' => $address]),
            ];
        }

        if ($subnet !== '') {
            $this->ensureWireguardFirewallRules($server, $name, $subnet);
        }

        return [
            'action' => 'unchanged',
            'message' => __('servers.profile_push_wg_ok', ['name' => $name]),
        ];
    }

    protected function ensureWireguardFirewallRules(Server $server, string $interfaceName, string $subnetCidr): void
    {
        if ($subnetCidr === '') {
            return;
        }

        try {
            app(MikrotikWireguardFirewallService::class)->ensureClientRules($server, $interfaceName, $subnetCidr);
        } catch (Throwable $exception) {
            Log::warning('WireGuard firewall rules ensure failed', [
                'server_id' => $server->id,
                'interface' => $interfaceName,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array{action: string, message: string}
     */
    protected function pushPppProfile(Server $server, ServerInterface $iface): array
    {
        $name = $iface->name;
        $meta = $iface->meta ?? [];
        $localAddress = $meta['local_address'] ?? null;
        $remoteAddress = $meta['remote_address'] ?? null;
        $useEncryption = (bool) ($meta['use_encryption'] ?? false);

        if ($remoteAddress !== null && $this->looksLikePoolName((string) $remoteAddress)) {
            $poolName = (string) $remoteAddress;
            $poolRanges = is_string($meta['pool_ranges'] ?? null) ? trim($meta['pool_ranges']) : '';

            if ($poolRanges !== '' && ! $this->mikrotik->ipPoolExists($server, $poolName)) {
                $this->mikrotik->ensureIpPool($server, $poolName, $poolRanges);
            }
        }

        if ($this->mikrotik->pppProfileExists($server, $name)) {
            return [
                'action' => 'unchanged',
                'message' => __('servers.profile_push_ppp_ok', ['name' => $name]),
            ];
        }

        $this->mikrotik->ensurePppProfile($server, $name, [
            'local_address' => $localAddress,
            'remote_address' => $remoteAddress,
            'use_encryption' => $useEncryption,
        ]);

        return [
            'action' => 'created',
            'message' => __('servers.profile_push_ppp_created', ['name' => $name]),
        ];
    }

    protected function looksLikePoolName(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && ! preg_match('/^\d/', $value);
    }
}
