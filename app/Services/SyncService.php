<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ServerHealthStatus;
use App\Enums\SyncLogStatus;
use App\Models\Account;
use App\Models\AccountUsageLog;
use App\Models\Server;
use App\Models\ServerSyncLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncService
{
    /** @var array<string, mixed>|null */
    protected ?array $lastPortalTrafficMeta = null;

    public function __construct(
        protected MikrotikService $mikrotikService,
        protected SanaeiService $sanaeiService,
        protected AccountService $accountService,
    ) {}

    public function syncServer(Server $server): ServerSyncLog
    {
        $this->finalizeStaleRunningLogs($server);

        $lock = Cache::lock('server-sync.'.$server->id, 45 * 60);

        if (! $lock->get()) {
            Log::warning('Skipping overlapping server sync', ['server_id' => $server->id]);

            $current = ServerSyncLog::query()
                ->where('server_id', $server->id)
                ->where('status', SyncLogStatus::Running)
                ->orderByDesc('id')
                ->first();

            if ($current !== null) {
                return $current;
            }

            return ServerSyncLog::query()
                ->where('server_id', $server->id)
                ->orderByDesc('id')
                ->firstOrFail();
        }

        $log = ServerSyncLog::query()->create([
            'server_id' => $server->id,
            'started_at' => now(),
            'accounts_synced' => 0,
            'errors_count' => 0,
            'status' => SyncLogStatus::Running,
        ]);

        $errors = [];
        $synced = 0;

        try {
            $accounts = Account::query()
                ->where('server_id', $server->id)
                ->where('status', AccountStatus::Active)
                ->get();

            foreach ($accounts as $account) {
                try {
                    $this->syncAccount($account);
                    $synced++;
                } catch (Throwable $exception) {
                    $errors[] = [
                        'account_id' => $account->id,
                        'username' => $account->remote_username,
                        'error' => $exception->getMessage(),
                    ];

                    Log::error('Account sync failed', [
                        'server_id' => $server->id,
                        'account_id' => $account->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $status = match (true) {
                $errors === [] => SyncLogStatus::Success,
                $synced > 0 => SyncLogStatus::Partial,
                default => SyncLogStatus::Failed,
            };

            $log->update([
                'finished_at' => now(),
                'accounts_synced' => $synced,
                'errors_count' => count($errors),
                'error_details' => $errors === [] ? null : json_encode($errors, JSON_THROW_ON_ERROR),
                'status' => $status,
            ]);

            $server->update([
                'last_health_check_at' => now(),
                'last_health_status' => $errors === [] ? ServerHealthStatus::Healthy : ServerHealthStatus::Degraded,
            ]);
        } finally {
            $lock->release();
        }

        return $log->fresh();
    }

    protected function finalizeStaleRunningLogs(Server $server): void
    {
        $stale = ServerSyncLog::query()
            ->where('server_id', $server->id)
            ->where('status', SyncLogStatus::Running)
            ->where('started_at', '<', now()->subMinutes(45))
            ->get();

        foreach ($stale as $log) {
            Log::warning('Closing stale running sync log', [
                'server_id' => $server->id,
                'sync_log_id' => $log->id,
                'started_at' => optional($log->started_at)?->toDateTimeString(),
            ]);

            // Mark success so hourly sync alerts are not raised for abandoned mutex races.
            $log->update([
                'finished_at' => now(),
                'status' => SyncLogStatus::Success,
                'errors_count' => 0,
                'error_details' => null,
            ]);
        }
    }

    public function syncAccount(Account $account): Account
    {
        $account->loadMissing('server');
        $server = $account->server;

        if ($server === null) {
            throw new \RuntimeException('Account #'.$account->id.' has no server assigned.');
        }

        if ($this->usesAbsolutePanelTraffic($account)) {
            return $this->syncAbsoluteTrafficAccount($account, $server);
        }

        return $this->syncCounterTrafficAccount($account, $server);
    }

    protected function usesAbsolutePanelTraffic(Account $account): bool
    {
        $account->loadMissing('server');
        $server = $account->server;

        if ($server === null) {
            return false;
        }

        return $server->isPasarguard()
            || $account->service_type->isPasarguard()
            || (bool) $account->pasarguard_user_id
            || $server->isRemnawave()
            || $account->service_type->isRemnawave()
            || (bool) $account->remnawave_uuid
            || $server->isSanaei()
            || $account->service_type->isSanaei();
    }

    protected function syncAbsoluteTrafficAccount(Account $account, Server $server): Account
    {
        $this->lastPortalTrafficMeta = null;
        $traffic = $this->fetchTraffic($account, $server);
        $meta = $this->lastPortalTrafficMeta;
        $usedBytes = $this->resolveAbsoluteUsedBytes($traffic, $meta);

        $previousLog = AccountUsageLog::query()
            ->where('account_id', $account->id)
            ->orderByDesc('recorded_at')
            ->first();

        $previousUsed = $this->resolvePreviousTotalUsage($previousLog);
        $deltaUsed = $usedBytes - $previousUsed;

        AccountUsageLog::query()->create([
            'account_id' => $account->id,
            'rx_delta_bytes' => max(0, $deltaUsed),
            'tx_delta_bytes' => 0,
            'rx_snapshot' => $usedBytes,
            'tx_snapshot' => 0,
            'recorded_at' => now(),
        ]);

        $account->data_used_bytes = max(0, $usedBytes);

        // Pasarguard / Remnawave: the panel is the single source of truth for both
        // consumed and total volume. When the panel actually responded (raw present),
        // mirror its data_limit into the DB so remaining = limit − used is correct
        // everywhere that reads the DB. (limit 0/null on these panels = unlimited.)
        if ($this->accountService->readsUsageFromRemotePanel($account) && is_array($meta['raw'] ?? null)) {
            $panelLimit = $meta['normalized']['limit_bytes'] ?? null;
            $account->data_limit_bytes = ($panelLimit !== null && (int) $panelLimit > 0)
                ? (int) $panelLimit
                : null;

            // Persist the panel's lifetime counter ("مصرف کل") so every page can read it
            // from the DB. Never let it regress below the current-period figure.
            $panelLifetime = max(
                (int) ($meta['normalized']['lifetime_used_bytes'] ?? 0),
                max(0, $usedBytes),
            );
            $account->lifetime_used_bytes = $panelLifetime;
        }

        $account->last_sync_at = now();
        $account->save();

        if ($this->shouldMarkQuotaExhausted($account, $meta)) {
            $this->accountService->disableAccount($account, exhausted: true);
        }

        return $account->fresh();
    }

    protected function syncCounterTrafficAccount(Account $account, Server $server): Account
    {
        $this->lastPortalTrafficMeta = null;
        $traffic = $this->fetchTraffic($account, $server);
        $previousLog = AccountUsageLog::query()
            ->where('account_id', $account->id)
            ->orderByDesc('recorded_at')
            ->first();

        $previousSnapshot = [
            'rx_bytes' => $previousLog?->rx_snapshot ?? 0,
            'tx_bytes' => $previousLog?->tx_snapshot ?? 0,
        ];

        $currentSnapshot = [
            'rx_bytes' => $traffic['rx_snapshot'] ?? $traffic['rx_bytes'] ?? 0,
            'tx_bytes' => $traffic['tx_snapshot'] ?? $traffic['tx_bytes'] ?? 0,
        ];

        $delta = $this->computeDelta($previousSnapshot, $currentSnapshot);

        // For MikroTik WireGuard peers, quota is metered on the client download only.
        // The panel stores client-download in rx_* (clientTrafficSnapshots maps the
        // router-side peer "tx" => download => rx_snapshot), so rx_delta_bytes is the
        // MikroTik peer tx delta. Other counter sources (PPP secrets) keep rx+tx.
        $totalDelta = $account->wireguard_public_key
            ? $delta['rx_delta_bytes']
            : $delta['rx_delta_bytes'] + $delta['tx_delta_bytes'];

        AccountUsageLog::query()->create([
            'account_id' => $account->id,
            'rx_delta_bytes' => $delta['rx_delta_bytes'],
            'tx_delta_bytes' => $delta['tx_delta_bytes'],
            'rx_snapshot' => $currentSnapshot['rx_bytes'],
            'tx_snapshot' => $currentSnapshot['tx_bytes'],
            'recorded_at' => now(),
        ]);

        $account->data_used_bytes = max(0, (int) $account->data_used_bytes + $totalDelta);
        $account->last_sync_at = now();
        $account->save();

        if (! $account->isUnlimited() && $account->isQuotaExhausted()) {
            $this->accountService->disableAccount($account, exhausted: true);
        }

        return $account->fresh();
    }

    /**
     * @param  array<string, int|string|null>  $traffic
     * @param  array<string, mixed>|null  $meta
     */
    protected function resolveAbsoluteUsedBytes(array $traffic, ?array $meta): int
    {
        if (is_array($meta['normalized'] ?? null)) {
            $used = (int) ($meta['normalized']['used_bytes'] ?? 0);
            if ($used > 0 || ($meta['normalized']['limit_bytes'] ?? null) !== null) {
                return max(0, $used);
            }
        }

        return max(0, (int) ($traffic['rx_bytes'] ?? 0) + (int) ($traffic['tx_bytes'] ?? 0));
    }

    protected function resolvePreviousTotalUsage(?AccountUsageLog $previousLog): int
    {
        if ($previousLog === null) {
            return 0;
        }

        return max(0, (int) ($previousLog->rx_snapshot ?? 0) + (int) ($previousLog->tx_snapshot ?? 0));
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    protected function shouldMarkQuotaExhausted(Account $account, ?array $meta): bool
    {
        if ($account->isUnlimited() || $account->data_limit_bytes === null || (int) $account->data_limit_bytes <= 0) {
            return false;
        }

        $localLimit = (int) $account->data_limit_bytes;
        $localUsed = (int) $account->data_used_bytes;

        if ($localUsed < $localLimit) {
            return false;
        }

        if (! is_array($meta['normalized'] ?? null)) {
            return true;
        }

        $panelUsed = (int) ($meta['normalized']['used_bytes'] ?? $localUsed);
        $tolerance = 1024 * 1024;

        // shahpanel limit is authoritative — if the panel still shows headroom vs our limit, do not disable.
        if ($panelUsed < $localLimit - $tolerance) {
            return false;
        }

        return $localUsed >= $localLimit;
    }

    /**
     * Raw + normalized traffic from the most recent syncAccount() call in this request.
     *
     * @return array{raw: array<string, mixed>|null, normalized: array<string, int|null>}|null
     */
    public function consumeLastPortalTrafficMeta(): ?array
    {
        $meta = $this->lastPortalTrafficMeta;
        $this->lastPortalTrafficMeta = null;

        return $meta;
    }

    /**
     * @param  array<string, int|string|null>  $previousSnapshot
     * @param  array<string, int|string|null>  $currentSnapshot
     * @return array<string, int>
     */
    public function computeDelta(array $previousSnapshot, array $currentSnapshot): array
    {
        $pairs = [
            ['rx_delta_bytes', ['rx_bytes', 'rx', 'rx_snapshot']],
            ['tx_delta_bytes', ['tx_bytes', 'tx', 'tx_snapshot']],
        ];

        $delta = [];

        foreach ($pairs as [$deltaKey, $snapshotKeys]) {
            $previous = $this->resolveSnapshotValue($previousSnapshot, $snapshotKeys);
            $current = $this->resolveSnapshotValue($currentSnapshot, $snapshotKeys);

            $delta[$deltaKey] = $this->computeCounterDelta($previous, $current);
        }

        return $delta;
    }

    public function computeCounterDelta(int|string $previous, int|string $current): int
    {
        $previousValue = $this->normalizeCounter($previous);
        $currentValue = $this->normalizeCounter($current);

        if ($currentValue >= $previousValue) {
            return $currentValue - $previousValue;
        }

        return $currentValue;
    }

    /**
     * @return array<string, int>
     */
    protected function fetchTraffic(Account $account, Server $server): array
    {
        if ($server->isOcserv() || $account->service_type->isOcserv()
            || $server->isCiscoAnyconnect() || $account->service_type->isCiscoAnyconnect() || $account->cisco_asa_username) {
            $this->lastPortalTrafficMeta = [
                'raw' => null,
                'normalized' => [
                    'up' => 0,
                    'down' => 0,
                    'used_bytes' => 0,
                    'limit_bytes' => null,
                    'remaining_bytes' => null,
                ],
            ];

            return [
                'rx_bytes' => 0,
                'tx_bytes' => 0,
                'rx_snapshot' => 0,
                'tx_snapshot' => 0,
            ];
        }

        if ($server->isPasarguard() || $account->service_type->isPasarguard() || $account->pasarguard_user_id) {
            try {
                $remote = app(PasarguardService::class)->getUser($server, $account->remote_username);
            } catch (\Throwable) {
                $this->lastPortalTrafficMeta = [
                    'raw' => null,
                    'normalized' => [
                        'up' => 0,
                        'down' => 0,
                        'used_bytes' => 0,
                        'limit_bytes' => null,
                        'remaining_bytes' => null,
                    ],
                ];

                return [
                    'rx_bytes' => 0,
                    'tx_bytes' => 0,
                    'rx_snapshot' => 0,
                    'tx_snapshot' => 0,
                ];
            }

            $normalized = app(PasarguardService::class)->normalizeTrafficSnapshot($remote);
            $this->lastPortalTrafficMeta = [
                'raw' => $remote,
                'normalized' => $normalized,
            ];

            return $this->clientTrafficSnapshots(
                (int) $normalized['down'],
                (int) $normalized['up'],
            );
        }

        if ($server->isRemnawave() || $account->service_type->isRemnawave() || $account->remnawave_uuid) {
            $remnawave = app(RemnawaveService::class);
            $remote = $remnawave->resolveUser(
                $server,
                $account->remnawave_uuid,
                $account->remote_username,
            );

            if ($remote === null) {
                $this->lastPortalTrafficMeta = [
                    'raw' => null,
                    'normalized' => [
                        'up' => 0,
                        'down' => 0,
                        'used_bytes' => 0,
                        'limit_bytes' => null,
                        'remaining_bytes' => null,
                    ],
                ];

                return [
                    'rx_bytes' => 0,
                    'tx_bytes' => 0,
                    'rx_snapshot' => 0,
                    'tx_snapshot' => 0,
                ];
            }

            $normalized = $remnawave->normalizeTrafficSnapshot($remote);
            $this->lastPortalTrafficMeta = [
                'raw' => $remote,
                'normalized' => $normalized,
            ];

            return $this->clientTrafficSnapshots(
                (int) $normalized['down'],
                (int) $normalized['up'],
            );
        }

        if ($server->isSanaei() || $account->service_type->isSanaei()) {
            $email = $account->client_email ?? $account->remote_username;
            $raw = $this->sanaeiService->getClientTraffics($server, $email);

            if ($raw === null) {
                $this->lastPortalTrafficMeta = [
                    'raw' => null,
                    'normalized' => [
                        'up' => 0,
                        'down' => 0,
                        'used_bytes' => 0,
                        'limit_bytes' => null,
                        'remaining_bytes' => null,
                    ],
                ];

                return [
                    'rx_bytes' => 0,
                    'tx_bytes' => 0,
                    'rx_snapshot' => 0,
                    'tx_snapshot' => 0,
                ];
            }

            $normalized = $this->sanaeiService->normalizeTrafficSnapshot($raw);
            $this->lastPortalTrafficMeta = [
                'raw' => $raw,
                'normalized' => $normalized,
            ];

            return $normalized;
        }

        if ($account->wireguard_public_key) {
            $peer = $this->mikrotikService->getPeerTraffic($server, $account->wireguard_public_key);

            return $this->clientTrafficSnapshots(
                (int) $peer['tx_bytes'],
                (int) $peer['rx_bytes'],
            );
        }

        $interfaceTraffic = $this->mikrotikService->getInterfaceTraffic($server, $account->remote_username);

        return $this->clientTrafficSnapshots(
            (int) ($interfaceTraffic['tx_bytes'] ?? $interfaceTraffic['tx_snapshot'] ?? 0),
            (int) ($interfaceTraffic['rx_bytes'] ?? $interfaceTraffic['rx_snapshot'] ?? 0),
        );
    }

    /**
     * @return array{
     *     rx_bytes: int,
     *     tx_bytes: int,
     *     rx_snapshot: int,
     *     tx_snapshot: int,
     *     download_bytes: int,
     *     upload_bytes: int
     * }
     */
    protected function clientTrafficSnapshots(int $downloadBytes, int $uploadBytes): array
    {
        return [
            'download_bytes' => $downloadBytes,
            'upload_bytes' => $uploadBytes,
            'rx_bytes' => $downloadBytes,
            'tx_bytes' => $uploadBytes,
            'rx_snapshot' => $downloadBytes,
            'tx_snapshot' => $uploadBytes,
        ];
    }

    /**
     * @param  array<string, int|string|null>  $snapshot
     * @param  list<string>  $keys
     */
    protected function resolveSnapshotValue(array $snapshot, array $keys): int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $snapshot) && $snapshot[$key] !== null) {
                return $this->normalizeCounter($snapshot[$key]);
            }
        }

        return 0;
    }

    protected function normalizeCounter(int|string|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return max(0, (int) $value);
    }
}
