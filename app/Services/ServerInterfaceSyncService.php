<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerInterface;
use Throwable;

class ServerInterfaceSyncService
{
    public function __construct(
        protected MikrotikService $mikrotikService,
        protected SanaeiService $sanaeiService,
        protected PasarguardService $pasarguardService,
        protected RemnawaveService $remnawaveService,
        protected MikrotikProfileService $profileService,
        protected MikrotikWireguardInterfaceService $wireguardInterfaceService,
        protected MikrotikPppProfileService $pppProfileService,
        protected ServerL2tpIpsecService $l2tpIpsecService,
    ) {}

    /**
     * @return array{synced: int, removed: int, lines: list<string>, errors: list<string>}
     */
    public function sync(Server $server): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('server_interfaces')) {
            return [
                'synced' => 0,
                'removed' => 0,
                'lines' => [],
                'errors' => ['جدول server_interfaces وجود ندارد. ابتدا migrate را از نگهداری DB یا maintain.php اجرا کنید.'],
            ];
        }

        return match (true) {
            $server->isMikrotik() => $this->syncMikrotikProfiles($server),
            $server->isPasarguard() && $server->isPasarguardReseller() => $this->skipPasarguardInboundSync($server),
            $server->isPasarguard() => $this->syncPasarguard($server),
            $server->isRemnawave() => $this->syncRemnawave($server),
            default => $this->syncSanaei($server),
        };
    }

    /**
     * @return array{synced: int, removed: int, lines: list<string>, errors: list<string>}
     */
    protected function syncMikrotikProfiles(Server $server): array
    {
        $lines = [];
        $errors = [];
        $pppRemoteKeys = [];
        $wireguardRemoteKeys = [];
        $synced = 0;
        $servicePorts = [];

        try {
            $servicePorts = $this->mikrotikService->detectEnabledServicePorts($server);
        } catch (Throwable $exception) {
            $errors[] = 'خطا در دریافت پورت سرویس‌ها: '.$exception->getMessage();
        }

        try {
            foreach ($this->mikrotikService->listPppProfiles($server) as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $key = $this->profileService->pppRemoteKey($name);
                $pppRemoteKeys[] = $key;
                $existing = ServerInterface::query()
                    ->where('server_id', $server->id)
                    ->where('remote_key', $key)
                    ->first();
                $primaryPort = $this->resolvePrimaryPppPort($servicePorts);
                $protocol = $existing?->protocol
                    ? (string) $existing->protocol
                    : $this->resolvePrimaryPppProtocol($servicePorts);
                $remoteAddress = $row['remote-address'] ?? null;
                $poolRanges = $this->resolvePppPoolRanges($server, $remoteAddress);
                $port = $existing?->port ?? $primaryPort;

                $meta = [
                    'profile_type' => 'ppp',
                    'ports' => $servicePorts,
                    'use_encryption' => ($row['use-encryption'] ?? 'no') === 'yes',
                    'local_address' => $row['local-address'] ?? null,
                    'remote_address' => $remoteAddress,
                    'pool_ranges' => $poolRanges,
                    'pool_name' => is_string($remoteAddress) && ! preg_match('/^\d/', trim($remoteAddress))
                        ? trim($remoteAddress)
                        : ($existing?->meta['pool_name'] ?? null),
                    'secret_count' => $this->mikrotikService->countPppSecretsOnProfile($server, $name),
                    'panel_account_count' => $this->pppProfileService->countPanelAccounts($server, $key),
                ];

                if ($existing && ($existing->meta['created_via_panel'] ?? false)) {
                    $meta['subnet'] = $existing->meta['subnet'] ?? null;
                    $meta['created_via_panel'] = true;
                }

                if ($existing && isset($existing->meta['speed_limit_mbps']) && (int) $existing->meta['speed_limit_mbps'] > 0) {
                    $meta['speed_limit_mbps'] = (int) $existing->meta['speed_limit_mbps'];
                }

                $this->upsertInterface($server, $key, $name, 'ppp', $meta, $protocol, $port);

                $synced++;
                $portLabel = $primaryPort ? " — پورت {$primaryPort} ({$protocol})" : '';
                $lines[] = "پروفایل PPP «{$name}»{$portLabel} ذخیره شد.";
            }
        } catch (Throwable $exception) {
            $errors[] = 'خطا در دریافت پروفایل‌های PPP: '.$exception->getMessage();
        }

        try {
            $wireguardSync = $this->wireguardInterfaceService->sync($server);
            $synced += $wireguardSync['synced'];
            $wireguardRemoteKeys = $wireguardSync['remote_keys'];
            $lines = array_merge($lines, $wireguardSync['lines']);
            $errors = array_merge($errors, $wireguardSync['errors']);
        } catch (Throwable $exception) {
            $errors[] = 'خطا در سینک اینترفیس WireGuard: '.$exception->getMessage();
        }

        $removed = $this->removeStale($server, $pppRemoteKeys, 'ppp')
            + $this->removeStale($server, $wireguardRemoteKeys, 'wireguard');

        if ($removed > 0) {
            $lines[] = "{$removed} پروفایل قدیمی حذف شد.";
        }

        try {
            $this->l2tpIpsecService->syncFromRouter($server);
        } catch (Throwable $exception) {
            $errors[] = 'خطا در سینک تنظیمات L2TP/IPsec: '.$exception->getMessage();
        }

        return compact('synced', 'removed', 'lines', 'errors');
    }

    /**
     * @return array{synced: int, removed: int, lines: list<string>, errors: list<string>}
     */
    protected function syncSanaei(Server $server): array
    {
        $lines = [];
        $errors = [];
        $remoteKeys = [];
        $synced = 0;

        try {
            foreach ($this->sanaeiService->listInbounds($server) as $inbound) {
                $id = (int) ($inbound['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $key = 'inbound:'.$id;
                $remoteKeys[] = $key;
                $name = (string) ($inbound['remark'] ?? $inbound['tag'] ?? "Inbound {$id}");
                $protocol = (string) ($inbound['protocol'] ?? '');

                $this->upsertInterface($server, $key, $name, 'inbound', [
                    'protocol' => $protocol,
                    'port' => $inbound['port'] ?? null,
                    'enable' => $inbound['enable'] ?? true,
                    'tag' => $inbound['tag'] ?? null,
                ], $protocol, isset($inbound['port']) ? (int) $inbound['port'] : null);

                $synced++;
                $lines[] = "Inbound «{$name}» (#{$id}) ذخیره شد.";
            }
        } catch (Throwable $exception) {
            $errors[] = 'خطا در دریافت inboundها: '.$exception->getMessage();
        }

        $removed = $this->removeStale($server, $remoteKeys);

        if ($removed > 0) {
            $lines[] = "{$removed} inbound قدیمی از دیتابیس حذف شد.";
        }

        return compact('synced', 'removed', 'lines', 'errors');
    }

    /**
     * @return array{synced: int, removed: int, lines: list<string>, errors: list<string>}
     */
    protected function skipPasarguardInboundSync(Server $server): array
    {
        return [
            'synced' => 0,
            'removed' => 0,
            'lines' => [__('servers.pasarguard_reseller_no_inbound_sync')],
            'errors' => [],
        ];
    }

    /**
     * @return array{synced: int, removed: int, lines: list<string>, errors: list<string>}
     */
    protected function syncPasarguard(Server $server): array
    {
        $lines = [];
        $errors = [];
        $remoteKeys = [];
        $synced = 0;

        try {
            foreach ($this->pasarguardService->listInboundTags($server) as $tag) {
                $tag = trim($tag);
                if ($tag === '') {
                    continue;
                }

                $key = 'inbound:'.md5($tag);
                $remoteKeys[] = $key;

                $this->upsertInterface($server, $key, $tag, 'inbound', [
                    'tag' => $tag,
                    'source' => 'pasarguard',
                ], null, null);

                $synced++;
                $lines[] = "Inbound «{$tag}» ذخیره شد.";
            }
        } catch (Throwable $exception) {
            $errors[] = 'خطا در دریافت inboundها: '.$exception->getMessage();
        }

        $removed = $this->removeStale($server, $remoteKeys);

        if ($removed > 0) {
            $lines[] = "{$removed} inbound قدیمی از دیتابیس حذف شد.";
        }

        return compact('synced', 'removed', 'lines', 'errors');
    }

    /**
     * @return array{synced: int, removed: int, lines: list<string>, errors: list<string>}
     */
    protected function syncRemnawave(Server $server): array
    {
        $result = $this->remnawaveService->syncCatalog($server);

        $synced = $result['squads'];
        $lines = $result['lines'];

        if ($result['squads'] > 0 || $result['nodes'] > 0) {
            $lines[] = __('servers.remnawave_sync_done', [
                'squads' => persian_digits($result['squads']),
                'nodes' => persian_digits($result['nodes']),
            ]);
        }

        if ($result['errors'] !== [] && $result['squads'] === 0) {
            $lines[] = __('servers.remnawave_sync_catalog_failed');
        }

        return [
            'synced' => $synced,
            'removed' => 0,
            'lines' => $lines,
            'errors' => $result['errors'],
        ];
    }

    /**
     * @param  array<string, int>  $servicePorts
     */
    protected function resolvePrimaryPppPort(array $servicePorts): ?int
    {
        foreach (['l2tp', 'pptp', 'ovpn', 'sstp'] as $service) {
            if (isset($servicePorts[$service])) {
                return (int) $servicePorts[$service];
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $servicePorts
     */
    protected function resolvePrimaryPppProtocol(array $servicePorts): string
    {
        foreach (['l2tp', 'pptp', 'ovpn', 'sstp'] as $service) {
            if (isset($servicePorts[$service])) {
                return $service;
            }
        }

        return 'ppp';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function upsertInterface(
        Server $server,
        string $remoteKey,
        string $name,
        string $category,
        array $meta = [],
        ?string $protocol = null,
        ?int $port = null
    ): void {
        ServerInterface::query()->updateOrCreate(
            [
                'server_id' => $server->id,
                'remote_key' => $remoteKey,
            ],
            [
                'name' => $name,
                'category' => $category,
                'protocol' => $protocol,
                'port' => $port,
                'is_enabled' => ! ($meta['disabled'] ?? false) && ($meta['enable'] ?? true),
                'meta' => $meta,
                'synced_at' => now(),
            ]
        );
    }

    /**
     * @param  list<string>  $remoteKeys
     */
    protected function removeStale(Server $server, array $remoteKeys, ?string $category = null): int
    {
        if ($remoteKeys === []) {
            return 0;
        }

        return ServerInterface::query()
            ->where('server_id', $server->id)
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->whereNotIn('remote_key', $remoteKeys)
            ->delete();
    }

    protected function resolvePppPoolRanges(Server $server, mixed $remoteAddress): ?string
    {
        if (! is_string($remoteAddress) || trim($remoteAddress) === '') {
            return null;
        }

        $remoteAddress = trim($remoteAddress);

        if (preg_match('/^\d/', $remoteAddress)) {
            return null;
        }

        try {
            foreach ($this->mikrotikService->listIpPools($server) as $pool) {
                if ((string) ($pool['name'] ?? '') === $remoteAddress) {
                    $ranges = (string) ($pool['ranges'] ?? '');

                    return $ranges !== '' ? $ranges : null;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    public function defaultWireguardInterface(Server $server): string
    {
        return app(MikrotikService::class)->resolveWireguardInterfaceName($server);
    }
}
