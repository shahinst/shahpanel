<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Exceptions\RemoteProvisionException;
use App\Models\Account;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\Server;
use App\Services\Remnawave\RemnawaveNodeCatalog;
use App\Services\Remnawave\RemnawavePanelClient;
use App\Services\Remnawave\RemnawaveSquadCatalog;
use App\Services\Remnawave\RemnawaveUserIdentity;
use App\Services\Remnawave\RemnawaveUserPayloadBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class RemnawaveService
{
    public function __construct(
        protected RemnawaveUserPayloadBuilder $payloadBuilder,
    ) {}

    /** @var array<int, RemnawavePanelClient> */
    protected array $clients = [];

    public function testConnection(Server $server): bool
    {
        return $this->testConnectionDetails($server)['ok'];
    }

    /**
     * @return array{ok: bool, message: string, error?: string, panel_url?: string, api_url?: string, panel_version?: string, inbound_count?: int, squad_count?: int, user_count?: int, debug?: list<array<string, mixed>>}
     */
    public function testConnectionDetails(Server $server): array
    {
        try {
            $result = $this->client($server)->testConnection();
            if ($result['ok'] ?? false) {
                $this->persistCatalogFromTestResult($server, $result);
                $server->refresh();
            }

            return $result;
        } catch (Throwable $exception) {
            Log::channel('remnawave')->warning('Remnawave connection test failed', [
                'server_id' => $server->id,
                'host' => $server->host,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => __('services.remnawave_connect_failed'),
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * دریافت Internal Squads (و node) از پنل Remnawave و ذخیره در سرور.
     *
     * @return array{squads: int, nodes: int, inbounds: int, lines: list<string>, errors: list<string>}
     */
    public function syncCatalog(Server $server): array
    {
        if (! $server->isRemnawave()) {
            throw new InvalidArgumentException(__('services.remnawave_operation_only'));
        }

        if (! $server->hasStoredRemnawaveApiToken()) {
            throw new InvalidArgumentException(__('services.remnawave_token_missing'));
        }

        $lines = [];
        $errors = [];

        try {
            $client = $this->client($server);
            $client->authenticate();

            $rawSquads = $client->listSquads();
            $squads = RemnawaveSquadCatalog::normalizeList($rawSquads);

            if ($squads === []) {
                $errors[] = 'هیچ Internal Squad از پنل برگردانده نشد (GET /api/internal-squads).';
            }

            $rawNodes = [];
            try {
                $rawNodes = $client->listNodes();
            } catch (Throwable $exception) {
                $errors[] = 'دریافت nodeها: '.$exception->getMessage();
            }
            $nodes = RemnawaveNodeCatalog::normalizeList($rawNodes);

            $inboundCount = 0;
            try {
                $inboundCount = count($client->listInbounds());
            } catch (Throwable $exception) {
                $errors[] = 'دریافت inboundها: '.$exception->getMessage();
            }

            $this->persistCatalogFromTestResult($server, [
                'ok' => true,
                'squads' => $squads,
                'nodes' => $nodes,
            ]);

            $server->refresh();
            $this->pruneStaleActiveSquads($server);

            foreach ($squads as $squad) {
                $lines[] = 'Squad «'.($squad['name'] ?? '').'» — '.($squad['uuid'] ?? '');
            }

            if ($inboundCount > 0) {
                $lines[] = persian_digits($inboundCount).' inbound از config-profiles خوانده شد.';
            }

            foreach ($nodes as $node) {
                $lines[] = 'Node «'.($node['name'] ?? '').'» — '.($node['address'] ?? '');
            }

            return [
                'squads' => count($squads),
                'nodes' => count($nodes),
                'inbounds' => $inboundCount,
                'lines' => $lines,
                'errors' => $errors,
            ];
        } catch (Throwable $exception) {
            return [
                'squads' => 0,
                'nodes' => 0,
                'inbounds' => 0,
                'lines' => $lines,
                'errors' => array_merge($errors, [$exception->getMessage()]),
            ];
        }
    }

    protected function pruneStaleActiveSquads(Server $server): void
    {
        $active = $server->remnawaveActiveSquadUuids();
        if ($active === []) {
            return;
        }

        $catalogUuids = array_column($server->remnawaveSquadCatalog(), 'uuid');
        $pruned = array_values(array_intersect($active, $catalogUuids));

        if ($pruned !== $active) {
            $server->update(['remnawave_active_squads' => $pruned]);
        }
    }

    /**
     * @param  array<string, mixed>  $testResult
     */
    public function persistSquadsFromTestResult(Server $server, array $testResult): void
    {
        $this->persistCatalogFromTestResult($server, $testResult);
    }

    /**
     * @param  array<string, mixed>  $testResult
     */
    public function persistCatalogFromTestResult(Server $server, array $testResult): void
    {
        if (! ($testResult['ok'] ?? false)) {
            return;
        }

        $updates = [];

        $squads = $testResult['squads'] ?? null;
        if (is_array($squads) && $squads !== []) {
            $normalizedSquads = RemnawaveSquadCatalog::normalizeList($squads);
            if ($normalizedSquads !== []) {
                $updates['remnawave_squads'] = $normalizedSquads;
                $updates['remnawave_squads_synced_at'] = now();
            }
        }

        $nodes = $testResult['nodes'] ?? null;
        if (is_array($nodes) && $nodes !== []) {
            $normalizedNodes = RemnawaveNodeCatalog::normalizeList($nodes);
            if ($normalizedNodes !== []) {
                $updates['remnawave_nodes'] = $normalizedNodes;
                $updates['remnawave_nodes_synced_at'] = now();
            }
        }

        if ($updates !== []) {
            $server->update($updates);
            $server->refresh();
            $this->pruneStaleActiveSquads($server);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInbounds(Server $server): array
    {
        return $this->client($server)->listInbounds();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSquads(Server $server): array
    {
        return $this->client($server)->listSquads();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listNodes(Server $server): array
    {
        return $this->client($server)->listNodes();
    }

    /**
     * @return list<array{uuid: string, name: string}>
     */
    public function storedSquads(Server $server): array
    {
        return RemnawaveSquadCatalog::forServer($server);
    }

    /**
     * @return list<array{uuid: string, name: string, address: string, port: int|null, is_connected: bool}>
     */
    public function storedNodes(Server $server): array
    {
        return RemnawaveNodeCatalog::forServer($server);
    }

    public function client(Server $server): RemnawavePanelClient
    {
        return $this->clients[$server->id] ??= new RemnawavePanelClient($server);
    }

    public function forgetClient(Server $server): void
    {
        unset($this->clients[$server->id]);
        RemnawavePanelClient::clearAuthCacheForServer($server->id);
    }

    public function payloadBuilder(): RemnawaveUserPayloadBuilder
    {
        return $this->payloadBuilder;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUser(Server $server, string $username): ?array
    {
        try {
            $payload = $this->client($server)->getUserByUsername($username);
        } catch (Throwable) {
            return null;
        }

        return $this->unwrapUser($payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUserByUuid(Server $server, string $uuid): ?array
    {
        try {
            $payload = $this->client($server)->getUserById($uuid);
        } catch (Throwable) {
            return null;
        }

        return $this->unwrapUser($payload);
    }

    /**
     * Resolve a remote user by stored identity (v2 uuid / v3 id), falling back to username.
     *
     * @return array<string, mixed>|null
     */
    public function resolveUser(Server $server, ?string $identifier, ?string $username = null): ?array
    {
        $identifier = trim((string) $identifier);
        if ($identifier !== '') {
            $byId = $this->getUserByUuid($server, $identifier);
            if ($byId !== null) {
                return $byId;
            }
        }

        $username = trim((string) $username);
        if ($username !== '') {
            return $this->getUser($server, $username);
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAllUsers(Server $server): array
    {
        $size = max(50, (int) config('shahpanel.remnawave.users_page_size', 200));
        $start = 0;
        $all = [];

        do {
            $page = $this->client($server)->getUsers(size: $size, start: $start);
            $batch = $page['users'] ?? [];
            if (! is_array($batch)) {
                break;
            }

            foreach ($batch as $row) {
                if (is_array($row)) {
                    $all[] = $row;
                }
            }

            $total = (int) ($page['total'] ?? count($all));
            $start += $size;
        } while (count($batch) === $size && $start < $total);

        return $all;
    }

    /**
     * @return array<string, mixed>
     */
    public function createPanelUser(
        Server $server,
        Package $package,
        PackageDuration $duration,
        string $username,
        ?int $dataLimitBytes,
        ?Carbon $expiryAt,
    ): array {
        $payload = $this->mergeActiveSquads(
            $this->payloadBuilder->buildCreate($package, $duration, $username, $dataLimitBytes, $expiryAt),
            $server,
            $package,
        );

        return $this->unwrapUser($this->client($server)->createUser($payload)) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function modifyPanelUser(
        Server $server,
        string $uuid,
        Package $package,
        PackageDuration $duration,
        ?int $dataLimitBytes,
        ?Carbon $expiryAt,
        bool $shouldEnable,
        ?int $usedTrafficBytes = null,
        ?string $username = null,
    ): array {
        $payload = $this->mergeActiveSquads(
            $this->payloadBuilder->buildUpdate(
                $uuid,
                $package,
                $duration,
                $dataLimitBytes,
                $expiryAt,
                $shouldEnable,
                $username,
            ),
            $server,
            $package,
        );

        // v2 may accept usedTrafficBytes; v3 dropped it from UpdateUser — ignore failures below.
        if ($usedTrafficBytes !== null && $usedTrafficBytes > 0) {
            try {
                $withTraffic = $payload;
                $withTraffic['usedTrafficBytes'] = $usedTrafficBytes;

                return $this->unwrapUser($this->client($server)->modifyUser($withTraffic)) ?? [];
            } catch (Throwable) {
                // fall through without usedTrafficBytes
            }
        }

        return $this->unwrapUser($this->client($server)->modifyUser($payload)) ?? [];
    }

    /**
     * Disable remote user without needing package/duration (safe for sync quota-exhaustion).
     *
     * @return array<string, mixed>
     */
    public function disablePanelUser(Server $server, string $userId, ?string $username = null): array
    {
        try {
            return $this->unwrapUser($this->client($server)->disableUser($userId)) ?? [];
        } catch (Throwable $exception) {
            // Some Remnawave builds only accept disable via PATCH status.
            if ($username === null || trim($username) === '') {
                throw $exception;
            }

            $payload = [
                'username' => $username,
                'status' => 'DISABLED',
            ];

            return $this->unwrapUser($this->client($server)->modifyUser($payload)) ?? [];
        }
    }

    /**
     * ساخت/به‌روزرسانی کاربر Remnawave فقط از فیلدهای دیتابیس shahpanel (بدون API ثنایی).
     *
     * @return array{action: string, message: string}
     */
    public function provisionUserFromDatabase(Account $account): array
    {
        $account->loadMissing(['server', 'package', 'packageDuration']);
        $server = $account->server;
        $package = $account->package;
        $username = (string) $account->remote_username;

        if ($server === null || ! $server->isRemnawave()) {
            throw new InvalidArgumentException(__('services.account_must_be_on_remnawave'));
        }

        if ($package === null) {
            throw new InvalidArgumentException(__('services.account_id_without_package', ['id' => $account->id]));
        }

        if (! $server->hasStoredRemnawaveApiToken()) {
            throw new InvalidArgumentException(__('services.remnawave_token_missing'));
        }

        $duration = $account->packageDuration
            ?? $package->durations()->where('is_enabled', true)->orderBy('sort_order')->first();

        if ($duration === null) {
            throw new InvalidArgumentException(__('services.package_duration_not_found'));
        }

        $this->resolveActiveSquadUuids($server, $package);

        $shouldEnable = $account->status === AccountStatus::Active
            && ! $account->isExpired()
            && ! $account->isQuotaExhausted();

        $existing = $this->getUser($server, $username);

        if ($existing === null) {
            $payload = $this->mergeActiveSquads(
                $this->payloadBuilder->buildCreate(
                    $package,
                    $duration,
                    $username,
                    $account->data_limit_bytes,
                    $account->expiry_at,
                ),
                $server,
                $package,
            );
            $payload['hwidDeviceLimit'] = 0;

            $remote = $this->unwrapUser($this->client($server)->createUser($payload)) ?? [];
            $this->persistRemnawaveFields($account, $remote);

            return [
                'action' => 'created',
                'message' => __('services.remnawave_user_created_from_db', ['username' => $username]),
            ];
        }

        $uuid = RemnawaveUserIdentity::fromRemoteUser($existing)
            ?? trim((string) ($account->remnawave_uuid ?? ''));
        if ($uuid === '') {
            throw new RemoteProvisionException(__('services.remnawave_user_not_found_unknown_id'));
        }

        $remote = $this->modifyPanelUser(
            $server,
            $uuid,
            $package,
            $duration,
            $account->data_limit_bytes,
            $account->expiry_at,
            $shouldEnable,
            max(0, (int) $account->data_used_bytes) ?: null,
            $username,
        );

        // hwidDeviceLimit is set on a second patch when supported.
        try {
            $this->client($server)->modifyUser(array_merge(
                RemnawaveUserIdentity::patchIdentity($uuid, $username),
                ['hwidDeviceLimit' => 0],
            ));
        } catch (Throwable) {
            // optional field
        }

        $this->persistRemnawaveFields($account, $remote);

        return [
            'action' => 'updated',
            'message' => __('services.remnawave_user_updated_from_db', ['username' => $username]),
        ];
    }

    public function removePanelUser(Server $server, string $uuid, ?string $username = null): void
    {
        $uuid = trim($uuid);
        $lastException = null;

        if ($uuid !== '') {
            try {
                $this->client($server)->removeUser($uuid);

                return;
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        $resolved = $this->resolveUser($server, null, $username);
        $id = $resolved !== null ? RemnawaveUserIdentity::fromRemoteUser($resolved) : null;
        if ($id === null || $id === $uuid) {
            throw $lastException ?? new RemoteProvisionException('کاربر Remnawave برای حذف یافت نشد.');
        }

        $this->client($server)->removeUser($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function resetUserTraffic(Server $server, string $uuid, ?string $username = null): array
    {
        $uuid = trim($uuid);
        $lastException = null;

        if ($uuid !== '') {
            try {
                return $this->client($server)->resetUserTraffic($uuid);
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        $resolved = $this->resolveUser($server, null, $username);
        $id = $resolved !== null ? RemnawaveUserIdentity::fromRemoteUser($resolved) : null;
        if ($id === null || $id === $uuid) {
            throw $lastException ?? new RemoteProvisionException('کاربر Remnawave برای ریست ترافیک یافت نشد.');
        }

        return $this->client($server)->resetUserTraffic($id);
    }

    /**
     * هم‌تراز کردن مصرف ثبت‌شده در shahpanel با Remnawave (در صورت پشتیبانی API).
     */
    public function syncPanelUsedTrafficFromAccount(Account $account): void
    {
        $account->loadMissing(['server', 'package', 'packageDuration']);
        $server = $account->server;
        $uuid = trim((string) ($account->remnawave_uuid ?? ''));

        if ($server === null || ! $server->isRemnawave() || $uuid === '' || $account->package === null) {
            return;
        }

        $usedBytes = (int) $account->data_used_bytes;
        if ($usedBytes <= 0) {
            return;
        }

        try {
            $duration = $account->packageDuration ?? $account->package->durations()->first();
            if ($duration === null) {
                return;
            }

            $shouldEnable = $account->status === \App\Enums\AccountStatus::Active
                && ! $account->isExpired()
                && ! $account->isQuotaExhausted();

            $this->modifyPanelUser(
                $server,
                $uuid,
                $account->package,
                $duration,
                $account->data_limit_bytes,
                $account->expiry_at,
                $shouldEnable,
                $usedBytes,
                (string) $account->remote_username,
            );
        } catch (Throwable) {
            // برخی نسخه‌های Remnawave این فیلد را نمی‌پذیرند — مصرف در shahpanel حفظ می‌شود.
        }
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array{up: int, down: int, used_bytes: int, limit_bytes: ?int, remaining_bytes: ?int, lifetime_used_bytes: int}
     */
    public function normalizeTrafficSnapshot(array $remote): array
    {
        // Current-period counter only — see PasarguardService::normalizeTrafficSnapshot note.
        $used = max(0, (int) ($remote['usedTrafficBytes'] ?? $remote['used_traffic_bytes'] ?? 0));

        $lifetimeUsed = max(0, (int) (
            $remote['lifetimeUsedTrafficBytes']
            ?? $remote['lifetime_used_traffic_bytes']
            ?? $used
        ));

        $limitRaw = $remote['trafficLimitBytes'] ?? $remote['traffic_limit_bytes'] ?? null;
        $limit = ($limitRaw !== null && (int) $limitRaw > 0) ? (int) $limitRaw : null;

        return [
            'up' => 0,
            'down' => $used,
            'used_bytes' => $used,
            'limit_bytes' => $limit,
            'remaining_bytes' => $limit !== null ? max(0, $limit - $used) : null,
            'lifetime_used_bytes' => $lifetimeUsed,
        ];
    }

    /**
     * @return list<string>
     */
    public function resolveActiveSquadUuids(Server $server, Package $package): array
    {
        $fromPackage = $package->remnawaveSquadUuids();
        if ($fromPackage !== []) {
            return $fromPackage;
        }

        $fromServerActive = $server->remnawaveActiveSquadUuids();
        if ($fromServerActive !== []) {
            return $fromServerActive;
        }

        try {
            $live = $this->listSquads($server);
            $uuids = array_values(array_filter(array_map(
                fn (array $row): string => (string) ($row['uuid'] ?? $row['id'] ?? ''),
                $live,
            ), fn (string $v): bool => $v !== ''));

            if ($uuids !== []) {
                return $uuids;
            }
        } catch (Throwable) {
            // fall through
        }

        throw new InvalidArgumentException(
            __('services.remnawave_no_active_squad')
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function mergeActiveSquads(array $payload, Server $server, Package $package): array
    {
        $existing = $payload['activeInternalSquads'] ?? null;
        if (is_array($existing) && $existing !== []) {
            return $payload;
        }

        $payload['activeInternalSquads'] = $this->resolveActiveSquadUuids($server, $package);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    protected function persistRemnawaveFields(Account $account, array $remote): void
    {
        $identity = RemnawaveUserIdentity::fromRemoteUser($remote);

        $account->update([
            'remnawave_uuid' => $identity ?? ((string) ($account->remnawave_uuid ?? '') ?: null),
            'remnawave_subscription_url' => (string) ($remote['subscriptionUrl'] ?? $account->remnawave_subscription_url) ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    protected function unwrapUser(array $payload): ?array
    {
        if (is_array($payload['user'] ?? null)) {
            $payload = $payload['user'];
        }

        if ($payload === []) {
            return null;
        }

        return RemnawaveUserIdentity::normalizeUserShape($payload);
    }
}
