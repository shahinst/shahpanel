<?php

namespace App\Services;

use App\Concerns\RetriesApiCalls;
use App\Exceptions\RemoteConnectionException;
use App\Exceptions\RemoteProvisionException;
use App\Models\Account;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConnectException;
use RouterOS\Query;
use Throwable;

class MikrotikService
{
    use RetriesApiCalls;

    /**
     * Per-process pool of authenticated RouterOS clients, keyed by server id, so
     * a burst of commands against the same router reuses a single socket/login.
     *
     * @var array<int, Client>
     */
    protected array $clientPool = [];

    public function connect(Server $server): Client
    {
        if (isset($this->clientPool[$server->id])) {
            return $this->clientPool[$server->id];
        }

        return $this->clientPool[$server->id] = $this->withRetry(
            fn () => $this->createClient($server),
            'mikrotik.connect',
            ['server_id' => $server->id, 'host' => $server->host]
        );
    }

    /**
     * Drop the pooled client for a server (called when a socket goes stale so the
     * next attempt reconnects cleanly).
     */
    public function flushClient(Server $server): void
    {
        unset($this->clientPool[$server->id]);
    }

    public function flushClients(): void
    {
        $this->clientPool = [];
    }

    /**
     * Run a callback against a pooled client, evicting the pooled client on any
     * failure so the next retry reconnects.
     *
     * @template T
     *
     * @param  callable(Client): T  $callback
     * @return T
     */
    protected function withPooledClient(Server $server, callable $callback, string $operation, array $context = [])
    {
        return $this->withRetry(
            fn () => $callback($this->connect($server)),
            $operation,
            array_merge(['server_id' => $server->id], $context),
            fn () => $this->flushClient($server),
        );
    }

    public function testConnection(Server $server): bool
    {
        return $this->testConnectionDetails($server)['ok'];
    }

    /**
     * Fast one-shot reachability check (no retry pool) for server selection.
     */
    public function probeConnection(Server $server, int $timeoutSeconds = 3): bool
    {
        try {
            $client = $this->createClient(
                $server,
                connectTimeout: max(1, $timeoutSeconds),
                socketTimeout: max(1, $timeoutSeconds),
                attempts: 1,
            );
            $client->query('/system/identity/print')->read();

            return true;
        } catch (Throwable $exception) {
            Log::info('MikroTik probe failed', [
                'server_id' => $server->id,
                'host' => $server->apiConnectionHost(),
                'port' => $server->port,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array{ok: bool, message: string, identity?: string, error?: string}
     */
    public function testConnectionDetails(Server $server): array
    {
        try {
            $client = $this->connect($server);
            $identity = $client->query('/system/identity/print')->read();
            $name = (string) ($identity[0]['name'] ?? 'MikroTik');

            return [
                'ok' => true,
                'message' => 'اتصال به MikroTik برقرار شد.',
                'identity' => $name,
            ];
        } catch (Throwable $exception) {
            Log::warning('MikroTik connection test failed', [
                'server_id' => $server->id,
                'host' => $server->host,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => 'اتصال به MikroTik ناموفق بود.',
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Run an arbitrary RouterOS command (add/set/remove) and return the first response row.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function sendCommand(Server $server, string $path, array $attributes = []): array
    {
        return $this->execute($server, $path, $attributes);
    }

    /**
     * One-off command with an extended read timeout (e.g. large /system/script/add).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function sendCommandWithSocketTimeout(Server $server, string $path, array $attributes, int $socketTimeout): array
    {
        return $this->withRetry(function () use ($server, $path, $attributes, $socketTimeout) {
            $client = $this->createClient($server, null, max(30, $socketTimeout));
            $query = new Query($path);

            foreach ($attributes as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $query->equal((string) $key, (string) $value);
            }

            $response = $client->query($query)->read();

            if ($response === []) {
                return [];
            }

            return is_array($response[0] ?? null) ? $response[0] : (array) ($response[0] ?? []);
        }, 'mikrotik.execute_long', [
            'path' => $path,
            'server_id' => $server->id,
        ]);
    }

    /**
     * Print rows matching a regex filter (router-side — avoids downloading whole menus).
     *
     * @param  list<string>  $proplist
     * @return list<array<string, mixed>>
     */
    public function queryRouterRegexFilter(
        Server $server,
        string $path,
        string $field,
        string $pattern,
        array $proplist = ['.id', 'comment', 'name'],
    ): array {
        return $this->withPooledClient($server, function (Client $client) use ($path, $field, $pattern, $proplist) {
            $query = new Query(rtrim($path, '/').'/print');
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $pattern);
            $query->add('?'.$field.'~"'.$escaped.'"');

            if ($proplist !== []) {
                $query->add('.proplist='.implode(',', $proplist));
            }

            return $client->query($query)->read();
        }, 'mikrotik.query_regex', [
            'path' => $path,
            'field' => $field,
            'pattern' => $pattern,
            'server_id' => $server->id,
        ]);
    }

    /**
     * Add a temporary /system/script, run it on the router, then remove it.
     * Used for bulk operations that would be too slow over many API round-trips.
     */
    public function runEphemeralScript(Server $server, string $source, int $socketTimeout = 180): void
    {
        $name = 'vpnl-wipe-'.substr(md5($source.microtime(true)), 0, 10);
        $timeout = max(60, $socketTimeout);

        $this->sendCommandWithSocketTimeout($server, '/system/script/add', [
            'name' => $name,
            'comment' => 'panel-ephemeral',
            'policy' => 'read,write,test,policy,password,sniff,sensitive,reboot',
            'dont-require-permissions' => 'yes',
            'source' => $source,
        ], $timeout);

        try {
            $this->sendCommandWithSocketTimeout($server, '/system/script/run', [
                'number' => $name,
            ], $timeout);
        } finally {
            try {
                $rows = $this->queryRouter($server, '/system/script/print', ['name' => $name]);

                foreach ($rows as $row) {
                    $id = $row['.id'] ?? null;

                    if ($id !== null) {
                        $this->sendCommand($server, '/system/script/remove', ['.id' => $id]);
                    }
                }
            } catch (Throwable $e) {
                Log::warning('mikrotik: ephemeral script cleanup failed', [
                    'server_id' => $server->id,
                    'name' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Run an arbitrary RouterOS print/query and return all rows.
     *
     * @param  array<string, string|int>  $filters  field => value, translated to ?field=value
     * @return list<array<string, mixed>>
     */
    public function queryRouter(Server $server, string $path, array $filters = []): array
    {
        return $this->withPooledClient($server, function (Client $client) use ($path, $filters) {
            $query = new Query($path);

            foreach ($filters as $field => $value) {
                $query->where((string) $field, (string) $value);
            }

            return $client->query($query)->read();
        }, 'mikrotik.query', ['path' => $path]);
    }

    /**
     * Short-timeout print for background metrics (never blocks the apply queue).
     *
     * @param  array<string, string|int>  $filters
     * @return list<array<string, mixed>>
     */
    public function queryRouterForMetrics(Server $server, string $path, array $filters = []): array
    {
        $connectTimeout = max(1, (int) config('tunneling.metrics.connect_timeout', 3));
        $socketTimeout = max(1, (int) config('tunneling.metrics.socket_timeout', 8));

        try {
            $client = $this->createClient($server, $connectTimeout, $socketTimeout, 1);
            $query = new Query($path);

            foreach ($filters as $field => $value) {
                $query->where((string) $field, (string) $value);
            }

            return $client->query($query)->read();
        } catch (Throwable $e) {
            throw new RemoteConnectionException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Run `/ping` and return every reply/summary row (not just the first).
     *
     * @param  array<string, string|int>  $attributes
     * @return list<array<string, mixed>>
     */
    public function ping(Server $server, array $attributes): array
    {
        return $this->withPooledClient($server, function (Client $client) use ($attributes) {
            $query = new Query('/ping');

            foreach ($attributes as $field => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $query->equal((string) $field, (string) $value);
            }

            $response = $client->query($query)->read();

            return is_array($response) ? $response : [];
        }, 'mikrotik.ping', ['address' => $attributes['address'] ?? '']);
    }

    /**
     * @param  array<string, string|int|bool>  $attributes
     * @return list<array<string, mixed>>
     */
    public function fetch(Server $server, array $attributes): array
    {
        return $this->withPooledClient($server, function (Client $client) use ($attributes) {
            $query = new Query('/tool/fetch');

            foreach ($attributes as $field => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $query->equal((string) $field, (string) $value);
            }

            $response = $client->query($query)->read();

            return is_array($response) ? $response : [];
        }, 'mikrotik.fetch', ['url' => $attributes['url'] ?? '']);
    }

    /**
     * Run `/tool/bandwidth-test` and return every progress/final row.
     *
     * @param  array<string, string|int|bool>  $attributes
     * @return list<array<string, mixed>>
     */
    public function runBandwidthTest(Server $server, array $attributes): array
    {
        return $this->withPooledClient($server, function (Client $client) use ($attributes) {
            $query = new Query('/tool/bandwidth-test');

            foreach ($attributes as $field => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $query->equal((string) $field, (string) $value);
            }

            $response = $client->query($query)->read();

            return is_array($response) ? $response : [];
        }, 'mikrotik.bandwidth-test', ['address' => $attributes['address'] ?? '']);
    }

    /**
     * Enable the RouterOS bandwidth-test server (required on the remote side).
     */
    public function ensureBandwidthServer(Server $server, bool $authenticate = false): void
    {
        $this->sendCommand($server, '/tool/bandwidth-server/set', [
            'enabled' => 'yes',
            'authenticate' => $authenticate ? 'yes' : 'no',
        ]);
    }

    /**
     * Whether at least one item matches the given filters.
     *
     * @param  array<string, string|int>  $filters
     */
    public function itemExists(Server $server, string $printPath, array $filters): bool
    {
        return $this->queryRouter($server, $printPath, $filters) !== [];
    }

    /**
     * Remove every item at $basePath whose $field exactly equals $value.
     *
     * @return int  number of items removed
     */
    public function removeMatching(Server $server, string $basePath, string $field, string $value): int
    {
        $base = rtrim($basePath, '/');
        $rows = $this->queryRouter($server, $base.'/print', [$field => $value]);
        $removed = 0;

        foreach ($rows as $row) {
            $id = $row['.id'] ?? null;
            if ($id === null) {
                continue;
            }

            // Per-item resilience: a single locked/dynamic row must not abort the
            // whole sweep, otherwise leftover config is never cleaned up.
            try {
                $this->execute($server, $base.'/remove', ['.id' => $id]);
                $removed++;
            } catch (Throwable $e) {
                Log::warning('MikroTik remove item failed', [
                    'server_id' => $server->id,
                    'path' => $base,
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $removed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFilterChain(Server $server, string $chain): array
    {
        try {
            return $this->queryRouter($server, '/ip/firewall/filter/print', ['chain' => $chain]);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Internal `.id` of the first drop/reject rule in a filter chain, or null.
     */
    public function firstDropOrRejectRuleId(Server $server, string $chain = 'input'): ?string
    {
        foreach ($this->listFilterChain($server, $chain) as $row) {
            $action = (string) ($row['action'] ?? '');
            if (in_array($action, ['drop', 'reject'], true) && isset($row['.id'])) {
                return (string) $row['.id'];
            }
        }

        return null;
    }

    /**
     * Whether $ruleId appears before $beforeId in the chain (evaluated first).
     */
    public function isFilterRuleBefore(Server $server, string $chain, string $ruleId, string $beforeId): bool
    {
        $ruleIndex = null;
        $beforeIndex = null;

        foreach ($this->listFilterChain($server, $chain) as $index => $row) {
            $id = (string) ($row['.id'] ?? '');
            if ($id === $ruleId) {
                $ruleIndex = $index;
            }
            if ($id === $beforeId) {
                $beforeIndex = $index;
            }
        }

        if ($ruleIndex === null || $beforeIndex === null) {
            return true;
        }

        return $ruleIndex < $beforeIndex;
    }

    public function moveFilterRuleBefore(Server $server, string $ruleId, string $destinationId): void
    {
        $this->sendCommand($server, '/ip/firewall/filter/move', [
            'numbers' => $ruleId,
            'destination' => $destinationId,
        ]);
    }

    /**
     * Robust cleanup: print every row at $basePath (no server-side filter) and
     * remove any whose $field CONTAINS $needle. This catches orphaned/legacy
     * objects that an exact match (removeMatching) would miss — e.g. configs
     * left behind after the DB rows drifted, exit indexes were reused, or an
     * older naming scheme was used. Never throws: a missing menu or a locked
     * row is logged and skipped so teardown always makes forward progress.
     *
     * @return int number of items removed
     */
    public function removeWhereContains(Server $server, string $basePath, string $field, string $needle): int
    {
        if ($needle === '') {
            return 0;
        }

        $base = rtrim($basePath, '/');

        try {
            $rows = $this->queryRouter($server, $base.'/print');
        } catch (Throwable $e) {
            Log::warning('MikroTik print for cleanup failed', [
                'server_id' => $server->id,
                'path' => $base,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        $removed = 0;

        foreach ($rows as $row) {
            $id = $row['.id'] ?? null;
            $fieldValue = (string) ($row[$field] ?? '');

            if ($id === null || $fieldValue === '' || ! str_contains($fieldValue, $needle)) {
                continue;
            }

            try {
                $this->execute($server, $base.'/remove', ['.id' => $id]);
                $removed++;
            } catch (Throwable $e) {
                Log::warning('MikroTik remove item failed', [
                    'server_id' => $server->id,
                    'path' => $base,
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $removed;
    }

    /**
     * Remove rows where ANY string field contains $needle (catches firewall rules
     * that reference tunnel interfaces in in-interface/out-interface with no comment).
     *
     * @return int number of items removed
     */
    public function removeWhereAnyFieldContains(Server $server, string $basePath, string $needle): int
    {
        if ($needle === '') {
            return 0;
        }

        $base = rtrim($basePath, '/');

        try {
            $rows = $this->queryRouter($server, $base.'/print');
        } catch (Throwable $e) {
            Log::warning('MikroTik print for any-field cleanup failed', [
                'server_id' => $server->id,
                'path' => $base,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        $removed = 0;

        foreach ($rows as $row) {
            $id = $row['.id'] ?? null;

            if ($id === null) {
                continue;
            }

            $matched = false;

            foreach ($row as $key => $value) {
                if ($key === '.id' || ! is_string($value) || $value === '') {
                    continue;
                }

                if (str_contains($value, $needle)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                continue;
            }

            try {
                $this->execute($server, $base.'/remove', ['.id' => $id]);
                $removed++;
            } catch (Throwable $e) {
                Log::warning('MikroTik remove item failed', [
                    'server_id' => $server->id,
                    'path' => $base,
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $removed;
    }

    /**
     * Remove a single item by its RouterOS internal id (.id).
     */
    public function removeById(Server $server, string $basePath, string $id): void
    {
        $this->execute($server, rtrim($basePath, '/').'/remove', ['.id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSystemResource(Server $server): array
    {
        return $this->withRetry(function () use ($server) {
            $client = $this->connect($server);
            $rows = $client->query('/system/resource/print')->read();

            return $rows[0] ?? [];
        }, 'mikrotik.system_resource', ['server_id' => $server->id]);
    }

    /**
     * Single-attempt, short-timeout resource read for the admin dashboard monitor (does not use the pooled client).
     *
     * @return array<string, mixed>
     */
    public function getSystemResourceForMonitor(Server $server): array
    {
        $connectTimeout = max(1, (int) config('vpnpanel.server_monitor.mikrotik_connect_timeout', 3));
        $socketTimeout = max(1, (int) config('vpnpanel.server_monitor.mikrotik_socket_timeout', 5));

        $client = $this->createClient($server, $connectTimeout, $socketTimeout, 1);
        $rows = $client->query('/system/resource/print')->read();

        return $rows[0] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPppProfiles(Server $server): array
    {
        return $this->withRetry(function () use ($server) {
            $client = $this->connect($server);

            return $client->query('/ppp/profile/print')->read();
        }, 'mikrotik.list_ppp_profiles', ['server_id' => $server->id]);
    }

    public function pppProfileExists(Server $server, string $name): bool
    {
        return $this->itemExists($server, '/ppp/profile/print', ['name' => $name]);
    }

    /**
     * @param  array{local_address?: string|null, remote_address?: string|null, use_encryption?: bool}  $attributes
     */
    public function ensurePppProfile(Server $server, string $name, array $attributes): void
    {
        if ($this->pppProfileExists($server, $name)) {
            return;
        }

        $payload = array_filter([
            'name' => $name,
            'local-address' => $attributes['local_address'] ?? null,
            'remote-address' => $attributes['remote_address'] ?? null,
            'use-encryption' => ($attributes['use_encryption'] ?? false) ? 'yes' : 'no',
            'change-tcp-mss' => 'yes',
        ], fn ($value) => $value !== null && $value !== '');

        $this->execute($server, '/ppp/profile/add', $payload);
    }

    public function ipPoolExists(Server $server, string $name): bool
    {
        return $this->itemExists($server, '/ip/pool/print', ['name' => $name]);
    }

    public function ensureIpPool(Server $server, string $name, string $ranges): void
    {
        if ($this->ipPoolExists($server, $name)) {
            return;
        }

        $this->execute($server, '/ip/pool/add', [
            'name' => $name,
            'ranges' => $ranges,
        ]);
    }

    /**
     * Create IP pool + PPP profile (MikroTik client subnet pattern: gateway .1, clients .2+).
     *
     * @return array{pool_name: string, gateway: string, subnet: string, ranges: string}
     */
    public function createPppProfileWithPool(
        Server $server,
        string $profileName,
        string $subnetCidr,
        string $poolName,
        bool $useEncryption = false,
    ): array {
        if ($this->pppProfileExists($server, $profileName)) {
            throw new RemoteProvisionException("پروفایل PPP «{$profileName}» از قبل روی روتر وجود دارد.");
        }

        [$rangeStart, $rangeEnd, $gateway] = $this->pppClientRangeFromSubnet($subnetCidr);
        $ranges = "{$rangeStart}-{$rangeEnd}";

        if (! $this->ipPoolExists($server, $poolName)) {
            $this->execute($server, '/ip/pool/add', [
                'name' => $poolName,
                'ranges' => $ranges,
            ]);
        }

        $this->execute($server, '/ppp/profile/add', array_filter([
            'name' => $profileName,
            'local-address' => $gateway,
            'remote-address' => $poolName,
            'use-encryption' => $useEncryption ? 'yes' : 'no',
            'change-tcp-mss' => 'yes',
        ], fn ($value) => $value !== null && $value !== ''));

        if (! $this->pppProfileExists($server, $profileName)) {
            throw new RemoteProvisionException("پروفایل PPP «{$profileName}» روی روتر ساخته نشد.");
        }

        return [
            'pool_name' => $poolName,
            'gateway' => $gateway,
            'subnet' => $subnetCidr,
            'ranges' => $ranges,
        ];
    }

    public function countPppSecretsOnProfile(Server $server, string $profileName): int
    {
        $count = 0;

        foreach ($this->listPppSecrets($server) as $secret) {
            if ((string) ($secret['profile'] ?? '') === $profileName) {
                $count++;
            }
        }

        return $count;
    }

    public function simpleQueueExists(Server $server, string $name): bool
    {
        return $this->itemExists($server, '/queue/simple/print', ['name' => $name]);
    }

    public function ensureSimpleQueue(
        Server $server,
        string $name,
        string $target,
        string $maxLimit,
        ?string $parent = null,
    ): void {
        $rows = $this->queryRouter($server, '/queue/simple/print', ['name' => $name]);
        $payload = array_filter([
            'name' => $name,
            'target' => $target,
            'max-limit' => $maxLimit,
            'parent' => $parent,
        ], fn ($value) => $value !== null && $value !== '');

        if ($rows !== [] && isset($rows[0]['.id'])) {
            $this->execute($server, '/queue/simple/set', array_merge(['.id' => $rows[0]['.id']], $payload));

            return;
        }

        $this->execute($server, '/queue/simple/add', $payload);
    }

    /**
     * Remove parent + per-host simple queues for a WireGuard interface (panel-managed naming).
     */
    public function removeWireguardInterfaceQueues(Server $server, string $interfaceName): int
    {
        $interfaceName = trim($interfaceName);
        $removed = 0;
        $prefix = $interfaceName.'-';

        foreach ($this->queryRouter($server, '/queue/simple/print') as $row) {
            $name = (string) ($row['name'] ?? '');
            $parent = (string) ($row['parent'] ?? '');
            $id = $row['.id'] ?? null;

            if ($id === null) {
                continue;
            }

            $isParent = $name === $interfaceName;
            $isChild = $parent === $interfaceName || str_starts_with($name, $prefix);

            if (! $isParent && ! $isChild) {
                continue;
            }

            try {
                $this->execute($server, '/queue/simple/remove', ['.id' => $id]);
                $removed++;
            } catch (Throwable $exception) {
                Log::warning('MikroTik queue remove failed', [
                    'server_id' => $server->id,
                    'queue' => $name,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $removed;
    }

    public function setWireguardInterfaceDisabled(Server $server, string $interfaceName, bool $disabled): void
    {
        $rows = $this->queryRouter($server, '/interface/wireguard/print', ['name' => $interfaceName]);

        if ($rows === [] || ! isset($rows[0]['.id'])) {
            throw new RemoteProvisionException(__('servers.wireguard_interface_missing', ['name' => $interfaceName]));
        }

        $this->execute($server, '/interface/wireguard/set', [
            '.id' => $rows[0]['.id'],
            'disabled' => $disabled ? 'yes' : 'no',
        ]);
    }

    public function renameWireguardInterface(Server $server, string $currentName, string $newName): void
    {
        $currentName = trim($currentName);
        $newName = trim($newName);

        if ($currentName === $newName) {
            return;
        }

        if ($this->wireguardInterfaceExists($server, $newName)) {
            throw new RemoteProvisionException(__('servers.wireguard_interface_exists', ['name' => $newName]));
        }

        $rows = $this->queryRouter($server, '/interface/wireguard/print', ['name' => $currentName]);

        if ($rows === [] || ! isset($rows[0]['.id'])) {
            throw new RemoteProvisionException(__('servers.wireguard_interface_missing', ['name' => $currentName]));
        }

        $this->execute($server, '/interface/wireguard/set', [
            '.id' => $rows[0]['.id'],
            'name' => $newName,
        ]);

        if (! $this->wireguardInterfaceExists($server, $newName)) {
            throw new RemoteProvisionException(__('servers.wireguard_rename_failed', [
                'from' => $currentName,
                'to' => $newName,
            ]));
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string} rangeStart, rangeEnd, gateway
     */
    protected function pppClientRangeFromSubnet(string $subnetCidr): array
    {
        [$base, $prefixRaw] = array_pad(explode('/', trim($subnetCidr), 2), 2, '24');
        $prefix = (int) $prefixRaw;
        $size = 1 << (32 - $prefix);
        $network = ip2long($base) & (~($size - 1) & 0xFFFFFFFF);
        $gateway = long2ip($network + 1);
        $rangeStart = long2ip($network + 2);
        $rangeEnd = long2ip($network + $size - 2);

        return [$rangeStart, $rangeEnd, $gateway];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listIpPools(Server $server): array
    {
        return $this->withRetry(function () use ($server) {
            $client = $this->connect($server);

            return $client->query('/ip/pool/print')->read();
        }, 'mikrotik.list_ip_pools', ['server_id' => $server->id]);
    }

    public function hasIpAddressOnInterface(Server $server, string $interfaceName, ?string $addressCidr = null): bool
    {
        foreach ($this->listIpAddresses($server) as $row) {
            $iface = (string) ($row['actual-interface'] ?? $row['interface'] ?? '');

            if ($iface !== $interfaceName) {
                continue;
            }

            if ($addressCidr === null) {
                return true;
            }

            $address = (string) ($row['address'] ?? '');

            if ($address === $addressCidr || str_starts_with($address, rtrim($addressCidr, '/'))) {
                return true;
            }
        }

        return false;
    }

    public function ensureIpAddress(Server $server, string $addressCidr, string $interfaceName): void
    {
        if ($this->hasIpAddressOnInterface($server, $interfaceName, $addressCidr)) {
            return;
        }

        $this->execute($server, '/ip/address/add', [
            'address' => $addressCidr,
            'interface' => $interfaceName,
        ]);
    }

    public function gatewayCidrFromSubnet(string $subnetCidr): string
    {
        return $this->computeGatewayCidrFromSubnet($subnetCidr);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listIpServices(Server $server): array
    {
        return $this->withRetry(function () use ($server) {
            $client = $this->connect($server);

            return $client->query('/ip/service/print')->read();
        }, 'mikrotik.list_ip_services', ['server_id' => $server->id]);
    }

    /**
     * @return array<string, int>
     */
    public function detectEnabledServicePorts(Server $server): array
    {
        $defaults = [
            'pptp' => 1723,
            'l2tp' => 1701,
            'sstp' => 443,
            'ovpn' => 1194,
        ];

        $ports = [];

        try {
            foreach ($this->listIpServices($server) as $row) {
                $name = strtolower((string) ($row['name'] ?? ''));
                if (! array_key_exists($name, $defaults)) {
                    continue;
                }

                if (($row['disabled'] ?? 'true') === 'true') {
                    continue;
                }

                $ports[$name] = (int) ($row['port'] ?? $defaults[$name]);
            }
        } catch (Throwable) {
            // fall through — interface-server probes below still apply
        }

        // Modern RouterOS exposes these under /interface/*-server, not always /ip/service.
        foreach ([
            'l2tp' => ['/interface/l2tp-server/server/print', '1701'],
            'pptp' => ['/interface/pptp-server/server/print', '1723'],
            'sstp' => ['/interface/sstp-server/server/print', '443'],
            'ovpn' => ['/interface/ovpn-server/server/print', '1194'],
        ] as $key => [$path, $fallbackPort]) {
            if (isset($ports[$key])) {
                continue;
            }

            try {
                $rows = $this->queryRouter($server, $path);
                $row = is_array($rows[0] ?? null) ? $rows[0] : null;
                if ($row === null) {
                    continue;
                }

                $disabled = strtolower((string) ($row['disabled'] ?? 'false'));
                if (in_array($disabled, ['true', 'yes', '1'], true)) {
                    continue;
                }

                $enabled = strtolower((string) ($row['enabled'] ?? 'true'));
                if (in_array($enabled, ['false', 'no', '0'], true)) {
                    continue;
                }

                $port = (int) ($row['port'] ?? $row['default-port'] ?? $fallbackPort);
                if ($port > 0) {
                    $ports[$key] = $port;
                }
            } catch (Throwable) {
                // service not present / unsupported on this router
            }
        }

        return $ports;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listNetworkInterfaces(Server $server): array
    {
        return $this->withRetry(function () use ($server) {
            $client = $this->connect($server);

            return $client->query('/interface/print')->read();
        }, 'mikrotik.list_interfaces', ['server_id' => $server->id]);
    }

    /**
     * Detect the physical WAN (internet egress) interface by reading the active
     * default route. Used to auto-populate the NAT `out-interface-list` when the
     * operator did not specify the WAN interface explicitly — without it the
     * tunnel masquerade rule matches no interface and client traffic is never
     * NATed to the internet.
     *
     * @param  list<string>  $excludeInterfaces  interface names that must never be treated as WAN (tunnel/loopback)
     */
    public function detectWanInterface(Server $server, array $excludeInterfaces = []): ?string
    {
        $routes = $this->queryRouter($server, '/ip/route/print', ['dst-address' => '0.0.0.0/0']);

        $exclude = array_map('strtolower', $excludeInterfaces);
        $candidates = [];

        foreach ($routes as $route) {
            $active = (string) ($route['active'] ?? '');
            $disabled = (string) ($route['disabled'] ?? 'false');

            if ($active !== 'true' || $disabled === 'true') {
                continue;
            }

            $iface = $this->routeEgressInterface($route);

            if ($iface === null || $iface === '') {
                continue;
            }

            if (in_array(strtolower($iface), $exclude, true)) {
                continue;
            }

            $distance = (int) ($route['distance'] ?? 0);
            $candidates[] = ['iface' => $iface, 'distance' => $distance];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return $candidates[0]['iface'];
    }

    /**
     * Next-hop IP of the active default route in `main` (underlay WAN gateway).
     *
     * @param  list<string>  $excludeInterfaces
     */
    public function detectWanGateway(Server $server, array $excludeInterfaces = []): ?string
    {
        $routes = $this->queryRouter($server, '/ip/route/print', [
            'dst-address' => '0.0.0.0/0',
            'routing-table' => 'main',
        ]);

        $exclude = array_map('strtolower', $excludeInterfaces);
        $candidates = [];

        foreach ($routes as $route) {
            if (($route['active'] ?? 'false') !== 'true' || ($route['disabled'] ?? 'false') === 'true') {
                continue;
            }

            $iface = $this->routeEgressInterface($route);
            if ($iface !== null && in_array(strtolower($iface), $exclude, true)) {
                continue;
            }

            $gateway = trim((string) ($route['gateway'] ?? ''));
            if ($gateway === '' || ! filter_var($gateway, FILTER_VALIDATE_IP)) {
                $immediate = (string) ($route['immediate-gw'] ?? '');
                if (str_contains($immediate, '%')) {
                    $gateway = trim(explode('%', $immediate)[0]);
                }
            }

            if ($gateway === '' || ! filter_var($gateway, FILTER_VALIDATE_IP)) {
                continue;
            }

            $candidates[] = [
                'gateway' => $gateway,
                'distance' => (int) ($route['distance'] ?? 255),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return $candidates[0]['gateway'];
    }

    /**
     * Extract the egress interface name from a RouterOS route row, tolerating the
     * different field shapes across ROS versions (`immediate-gw` "1.2.3.4%ether1",
     * a bare `interface`, or a `gateway` that is itself an interface name).
     *
     * @param  array<string, mixed>  $route
     */
    protected function routeEgressInterface(array $route): ?string
    {
        $immediate = (string) ($route['immediate-gw'] ?? '');
        if ($immediate !== '') {
            // May be a comma-separated ECMP list: take the first usable entry.
            foreach (explode(',', $immediate) as $part) {
                if (str_contains($part, '%')) {
                    $iface = trim((string) substr(strrchr($part, '%'), 1));
                    if ($iface !== '') {
                        return $iface;
                    }
                }
            }
        }

        $interface = trim((string) ($route['interface'] ?? ''));
        if ($interface !== '') {
            return $interface;
        }

        $gateway = trim((string) ($route['gateway'] ?? ''));
        if ($gateway !== '' && ! str_contains($gateway, '.') && ! str_contains($gateway, ':') && ! ctype_digit($gateway)) {
            return $gateway;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listWireguardInterfaces(Server $server): array
    {
        return $this->withRetry(function () use ($server) {
            $client = $this->connect($server);

            return $client->query('/interface/wireguard/print')->read();
        }, 'mikrotik.list_wireguard_interfaces', ['server_id' => $server->id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPppSecrets(Server $server): array
    {
        return $this->withRetry(function () use ($server) {
            $client = $this->connect($server);

            return $client->query('/ppp/secret/print')->read();
        }, 'mikrotik.list_ppp_secrets', ['server_id' => $server->id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listWireguardPeers(Server $server, ?string $interface = null): array
    {
        return $this->withRetry(function () use ($server, $interface) {
            $client = $this->connect($server);
            $query = new Query('/interface/wireguard/peers/print');

            if ($interface !== null) {
                $query->where('interface', $interface);
            }

            return $client->query($query)->read();
        }, 'mikrotik.list_wireguard_peers', ['server_id' => $server->id]);
    }

    public function pppSecretExists(Server $server, string $username): bool
    {
        return $this->findSecret($server, $username) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPppSecret(Server $server, string $username): ?array
    {
        return $this->findSecret($server, $username);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getL2tpServerConfig(Server $server): ?array
    {
        $results = $this->withRetry(function () use ($server) {
            return $this->queryRouter($server, '/interface/l2tp-server/server/print');
        }, 'mikrotik.l2tp_server_config', ['server_id' => $server->id]);

        return $results[0] ?? null;
    }

    public function wireguardPeerExists(Server $server, string $publicKey): bool
    {
        return $this->findWireguardPeer($server, $publicKey) !== null;
    }

    public function resolveWireguardInterfaceName(Server $server, ?string $legacyKey = null): string
    {
        if ($legacyKey !== null && $legacyKey !== '') {
            if (preg_match('/^profile:wg:(.+)$/', $legacyKey, $matches)) {
                return $matches[1];
            }

            if (preg_match('/^wg:(.+)$/', $legacyKey, $matches)) {
                return $matches[1];
            }

            return $legacyKey;
        }

        $names = $this->listWireguardInterfaceNames($server, preferEnabled: true);

        if ($names !== []) {
            return $names[0];
        }

        $allNames = $this->listWireguardInterfaceNames($server, preferEnabled: false);

        if ($allNames !== []) {
            return $allNames[0];
        }

        throw new RemoteProvisionException(
            'اینترفیس WireGuard روی روتر یافت نشد — ابتدا یک اینترفیس WireGuard در MikroTik بسازید.'
        );
    }

    /**
     * @return list<string>
     */
    public function listWireguardInterfaceNames(Server $server, bool $preferEnabled = true): array
    {
        $names = [];

        foreach ($this->listWireguardInterfaces($server) as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }

            if ($preferEnabled && $this->isRouterOsDisabled($row['disabled'] ?? null)) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    public function wireguardInterfaceExists(Server $server, string $interface): bool
    {
        foreach ($this->listWireguardInterfaces($server) as $row) {
            if (($row['name'] ?? null) === $interface) {
                return true;
            }
        }

        return false;
    }

    public function wireguardPeerInterface(Server $server, string $publicKey): ?string
    {
        $peer = $this->findWireguardPeer($server, $publicKey);
        $interface = (string) ($peer['interface'] ?? '');

        return $interface !== '' ? $interface : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listIpAddresses(Server $server): array
    {
        return $this->withRetry(function () use ($server) {
            $client = $this->connect($server);

            return $client->query('/ip/address/print')->read();
        }, 'mikrotik.list_ip_addresses', ['server_id' => $server->id]);
    }

    public function getInterfaceGatewayAddress(Server $server, string $interfaceName): ?string
    {
        foreach ($this->listIpAddresses($server) as $row) {
            $iface = (string) ($row['actual-interface'] ?? $row['interface'] ?? '');

            if ($iface !== $interfaceName) {
                continue;
            }

            $address = (string) ($row['address'] ?? '');

            return $address !== '' ? $address : null;
        }

        return null;
    }

    public function countWireguardPeersOnInterface(Server $server, string $interfaceName): int
    {
        $count = 0;

        foreach ($this->listWireguardPeers($server, $interfaceName) as $peer) {
            if (! $this->isRouterOsDisabled($peer['disabled'] ?? null)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Create WireGuard interface + gateway /ip/address on the router.
     *
     * @return array{name: string, subnet: string, gateway: string, listen_port: int, public_key: string}
     */
    public function createWireguardInterface(
        Server $server,
        string $name,
        string $subnetCidr,
        ?int $listenPort = null,
    ): array {
        if ($this->wireguardInterfaceExists($server, $name)) {
            throw new RemoteProvisionException("اینترفیس WireGuard «{$name}» از قبل روی روتر وجود دارد.");
        }

        $keys = $this->generateKeys();
        $port = $listenPort ?? $this->pickNextWireguardListenPort($server);
        $gateway = $this->computeGatewayCidrFromSubnet($subnetCidr);

        $this->execute($server, '/interface/wireguard/add', array_filter([
            'name' => $name,
            'listen-port' => (string) $port,
            'private-key' => $keys['private_key'],
            'mtu' => (string) config('vpnpanel.wireguard.mtu', 1380),
        ], fn ($value) => $value !== null && $value !== ''));

        if (! $this->wireguardInterfaceExists($server, $name)) {
            throw new RemoteProvisionException("اینترفیس WireGuard «{$name}» روی روتر ساخته نشد.");
        }

        $this->execute($server, '/ip/address/add', [
            'address' => $gateway,
            'interface' => $name,
        ]);

        return [
            'name' => $name,
            'subnet' => $subnetCidr,
            'gateway' => $gateway,
            'listen_port' => $port,
            'public_key' => $keys['public_key'],
        ];
    }

    protected function pickNextWireguardListenPort(Server $server): int
    {
        $used = [];

        foreach ($this->listWireguardInterfaces($server) as $row) {
            if (isset($row['listen-port'])) {
                $used[] = (int) $row['listen-port'];
            }
        }

        $port = (int) config('vpnpanel.wireguard.default_listen_port', 51820);

        while (in_array($port, $used, true)) {
            $port++;
        }

        return $port;
    }

    protected function computeGatewayCidrFromSubnet(string $subnetCidr): string
    {
        [$base, $prefixRaw] = array_pad(explode('/', trim($subnetCidr), 2), 2, '24');
        $prefix = (int) $prefixRaw;
        $size = 1 << (32 - $prefix);
        $network = ip2long($base) & (~($size - 1) & 0xFFFFFFFF);

        return long2ip($network + 1).'/'.$prefix;
    }

    /**
     * @return array{name: string, public_key: string, listen_port: int}|null
     */
    public function getWireguardInterfaceDetails(Server $server, ?string $interfaceName = null): ?array
    {
        $target = $interfaceName ?? $this->resolveWireguardInterfaceName($server);

        $interfaces = $this->withRetry(function () use ($server) {
            $client = $this->connect($server);

            return $client->query('/interface/wireguard/print')->read();
        }, 'mikrotik.wireguard_interface_details', ['server_id' => $server->id]);

        foreach ($interfaces as $row) {
            if (($row['name'] ?? null) !== $target) {
                continue;
            }

            $publicKey = (string) ($row['public-key'] ?? '');

            if ($publicKey === '') {
                return null;
            }

            return [
                'name' => $target,
                'public_key' => $publicKey,
                'listen_port' => (int) ($row['listen-port'] ?? 51820),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function createUser(Server $server, array $attributes): array
    {
        return $this->mutateSecret($server, 'add', $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function updateUser(Server $server, string $username, array $attributes): array
    {
        $secret = $this->findSecret($server, $username);

        if ($secret === null) {
            throw new RemoteProvisionException("PPP secret [{$username}] not found.");
        }

        return $this->mutateSecret($server, 'set', array_merge($attributes, ['.id' => $secret['.id']]));
    }

    public function deleteUser(Server $server, string $username): void
    {
        $username = trim($username);

        if ($username === '') {
            return;
        }

        try {
            $this->disconnectActivePppSessions($server, $username);
        } catch (Throwable $exception) {
            Log::warning('MikroTik PPP active session disconnect failed', [
                'server_id' => $server->id,
                'username' => $username,
                'error' => $exception->getMessage(),
            ]);
        }

        $secret = $this->findSecret($server, $username);

        if ($secret === null) {
            return;
        }

        try {
            $this->execute($server, '/ppp/secret/remove', ['.id' => $secret['.id']]);
        } catch (Throwable $exception) {
            // A retried remove (e.g. after a dropped connection on the first,
            // already-successful attempt) reports "no such item" — the secret
            // is already gone, which is the desired end state, not a failure.
            if (str_contains(strtolower($exception->getMessage()), 'no such item')) {
                return;
            }

            throw $exception;
        }
    }

    public function disconnectActivePppSessions(Server $server, string $username): void
    {
        $username = trim($username);

        if ($username === '') {
            return;
        }

        $this->withPooledClient($server, function (Client $client) use ($username): void {
            $filter = (new Query('/ppp/active/print'))->where('name', $username);
            $results = $client->query($filter)->read();

            if (! is_array($results)) {
                return;
            }

            foreach ($results as $row) {
                if (! is_array($row) || ($row['name'] ?? null) !== $username) {
                    continue;
                }

                $id = $row['.id'] ?? null;

                if ($id === null || $id === '') {
                    continue;
                }

                $removeQuery = (new Query('/ppp/active/remove'))->equal('.id', (string) $id);
                $client->query($removeQuery)->read();
            }
        }, 'mikrotik.ppp_disconnect_active', ['username' => $username]);
    }

    public function enableUser(Server $server, string $username): void
    {
        $this->setSecretDisabled($server, $username, false);
    }

    public function disableUser(Server $server, string $username): void
    {
        $this->setSecretDisabled($server, $username, true);
    }

    /**
     * @return array{private_key: string, public_key: string}
     */
    public function generateKeys(): array
    {
        $privateKeyRaw = random_bytes(32);
        $privateKeyRaw[0] = chr(ord($privateKeyRaw[0]) & 248);
        $privateKeyRaw[31] = chr((ord($privateKeyRaw[31]) & 127) | 64);

        $privateKey = base64_encode($privateKeyRaw);
        $publicKey = base64_encode(sodium_crypto_scalarmult_base($privateKeyRaw));

        return [
            'private_key' => $privateKey,
            'public_key' => $publicKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $peerData
     * @return array<string, mixed>
     */
    public function addPeer(Server $server, string $interface, array $peerData): array
    {
        if (! $this->wireguardInterfaceExists($server, $interface)) {
            $available = implode(', ', $this->listWireguardInterfaceNames($server, preferEnabled: false));

            throw new RemoteProvisionException(
                "اینترفیس WireGuard «{$interface}» روی روتر وجود ندارد."
                .($available !== '' ? " اینترفیس‌های موجود: {$available}" : '')
            );
        }

        $keepaliveSeconds = (int) ($peerData['persistent_keepalive'] ?? $server->wireguardPersistentKeepalive());

        $payload = array_filter([
            'interface' => $interface,
            'public-key' => $peerData['public_key'] ?? null,
            'private-key' => $peerData['private_key'] ?? null,
            'allowed-address' => $peerData['allowed_address'] ?? '0.0.0.0/0',
            'comment' => $peerData['comment'] ?? $peerData['name'] ?? null,
            'persistent-keepalive' => $keepaliveSeconds > 0
                ? self::formatPersistentKeepalive($keepaliveSeconds)
                : null,
        ], fn ($value) => $value !== null && $value !== '');

        return $this->execute($server, '/interface/wireguard/peers/add', $payload);
    }

    /** RouterOS peer keepalive interval (seconds → hh:mm:ss, e.g. 10 → 00:00:10). */
    public static function formatPersistentKeepalive(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60,
        );
    }

    public function removePeer(Server $server, string $publicKey): void
    {
        $peer = $this->findWireguardPeer($server, $publicKey);

        if ($peer === null) {
            return;
        }

        try {
            $this->execute($server, '/interface/wireguard/peers/remove', ['.id' => $peer['.id']]);
        } catch (Throwable $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'no such item')) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $peerData
     */
    public function updatePeer(Server $server, string $publicKey, array $peerData): void
    {
        $peer = $this->findWireguardPeer($server, $publicKey);

        if ($peer === null) {
            throw new RemoteProvisionException('WireGuard peer روی سرور یافت نشد.');
        }

        $payload = ['.id' => $peer['.id']];

        if (isset($peerData['allowed_address'])) {
            $payload['allowed-address'] = (string) $peerData['allowed_address'];
        }

        if (isset($peerData['interface'])) {
            $payload['interface'] = (string) $peerData['interface'];
        }

        if (isset($peerData['comment'])) {
            $payload['comment'] = (string) $peerData['comment'];
        }

        if (count($payload) === 1) {
            return;
        }

        $this->execute($server, '/interface/wireguard/peers/set', $payload);
    }

    public function disablePeer(Server $server, string $publicKey): void
    {
        $this->setPeerDisabled($server, $publicKey, true);
    }

    public function enablePeer(Server $server, string $publicKey): void
    {
        $this->setPeerDisabled($server, $publicKey, false);
    }

    public function setPeerDisabled(Server $server, string $publicKey, bool $disabled): void
    {
        $peer = $this->findWireguardPeer($server, $publicKey);

        if ($peer === null) {
            if ($disabled) {
                return;
            }

            throw new RemoteProvisionException('WireGuard peer روی سرور یافت نشد.');
        }

        $this->execute($server, '/interface/wireguard/peers/set', [
            '.id' => $peer['.id'],
            'disabled' => $disabled ? 'yes' : 'no',
        ]);
    }

    /**
     * @return array{rx_bytes: int, tx_bytes: int}
     */
    public function getPeerTraffic(Server $server, string $publicKey): array
    {
        $peer = $this->findWireguardPeer($server, $publicKey);

        if ($peer === null) {
            return ['rx_bytes' => 0, 'tx_bytes' => 0];
        }

        return [
            'rx_bytes' => (int) ($peer['rx'] ?? $peer['rx-byte'] ?? 0),
            'tx_bytes' => (int) ($peer['tx'] ?? $peer['tx-byte'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function buildClientConfig(
        Server $server,
        string $privateKey,
        string $address,
        string $publicKey,
        array $options = []
    ): string {
        $endpoint = $options['endpoint'] ?? $server->vpnClientEndpointHost().':'.($options['listen_port'] ?? 51820);
        $dns = $options['dns'] ?? config('vpnpanel.wireguard.dns', '1.1.1.1');
        $allowedIps = $options['allowed_ips'] ?? config('vpnpanel.wireguard.allowed_ips', '0.0.0.0/0, ::/0');
        $keepalive = (int) ($options['persistent_keepalive'] ?? $server->wireguardPersistentKeepalive());
        $mtu = (int) ($options['mtu'] ?? config('vpnpanel.wireguard.mtu', 1380));

        return implode("\n", [
            '[Interface]',
            'PrivateKey = '.$privateKey,
            'Address = '.$address,
            'DNS = '.$dns,
            'MTU = '.$mtu,
            '',
            '[Peer]',
            'PublicKey = '.$publicKey,
            'AllowedIPs = '.$allowedIps,
            'Endpoint = '.$endpoint,
            'PersistentKeepalive = '.$keepalive,
        ])."\n";
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function createSecret(Server $server, array $attributes, string $service = 'any'): array
    {
        return $this->createUser($server, array_merge($attributes, [
            'service' => $service,
        ]));
    }

    public function deleteSecret(Server $server, string $username): void
    {
        $this->deleteUser($server, $username);
    }

    /**
     * @return array{rx_bytes: int, tx_bytes: int, rx_snapshot: int, tx_snapshot: int}
     */
    public function getInterfaceTraffic(Server $server, string $identifier): array
    {
        $secret = $this->findSecret($server, $identifier);

        if ($secret !== null) {
            return [
                'rx_bytes' => (int) ($secret['bytes-in'] ?? 0),
                'tx_bytes' => (int) ($secret['bytes-out'] ?? 0),
                'rx_snapshot' => (int) ($secret['bytes-in'] ?? 0),
                'tx_snapshot' => (int) ($secret['bytes-out'] ?? 0),
            ];
        }

        $peer = $this->findWireguardPeer($server, $identifier);

        if ($peer !== null) {
            $rx = (int) ($peer['rx'] ?? $peer['rx-byte'] ?? 0);
            $tx = (int) ($peer['tx'] ?? $peer['tx-byte'] ?? 0);

            return [
                'rx_bytes' => $rx,
                'tx_bytes' => $tx,
                'rx_snapshot' => $rx,
                'tx_snapshot' => $tx,
            ];
        }

        $stats = $this->readInterfaceStats($server, $identifier);

        return [
            'rx_bytes' => $stats['rx_bytes'],
            'tx_bytes' => $stats['tx_bytes'],
            'rx_snapshot' => $stats['rx_bytes'],
            'tx_snapshot' => $stats['tx_bytes'],
        ];
    }

    public function getAccountTraffic(Server $server, Account $account): array
    {
        if ($account->wireguard_public_key) {
            return $this->getPeerTraffic($server, $account->wireguard_public_key);
        }

        return $this->getInterfaceTraffic($server, $account->remote_username);
    }

    public function generateOvpnConfig(Server $server, Account $account, array $options = []): string
    {
        $remote = $options['remote'] ?? $server->vpnClientEndpointHost();
        $port = $options['port'] ?? 1194;
        $proto = $options['proto'] ?? 'udp';
        $ca = $options['ca'] ?? '';
        $cert = $options['cert'] ?? '';
        $key = $options['key'] ?? '';

        $lines = [
            'client',
            'dev tun',
            'proto '.$proto,
            'remote '.$remote.' '.$port,
            'resolv-retry infinite',
            'nobind',
            'persist-key',
            'persist-tun',
            'remote-cert-tls server',
            'auth-user-pass',
            'verb 3',
        ];

        if ($ca !== '') {
            $lines[] = '<ca>';
            $lines[] = $ca;
            $lines[] = '</ca>';
        }

        if ($cert !== '') {
            $lines[] = '<cert>';
            $lines[] = $cert;
            $lines[] = '</cert>';
        }

        if ($key !== '') {
            $lines[] = '<key>';
            $lines[] = $key;
            $lines[] = '</key>';
        }

        $lines[] = '# Username: '.$account->remote_username;

        return implode("\n", $lines)."\n";
    }

    protected function createClient(
        Server $server,
        ?int $connectTimeout = null,
        ?int $socketTimeout = null,
        ?int $attempts = null,
    ): Client {
        $username = $server->username_enc;
        $password = $server->password_enc;

        if ($username === null || $password === null) {
            throw new RemoteConnectionException('MikroTik credentials are not configured for server #'.$server->id);
        }

        $port = $server->port ?: config('vpnpanel.mikrotik.default_port', 8728);
        $sslPort = config('vpnpanel.mikrotik.ssl_port', 8729);
        $useSsl = (int) $port === (int) $sslPort;

        try {
            return new Client(new Config([
                'host' => $server->apiConnectionHost(),
                'user' => $username,
                'pass' => $password,
                'port' => (int) $port,
                'ssl' => $useSsl,
                'timeout' => max(1, $connectTimeout ?? (int) config('vpnpanel.mikrotik.connect_timeout', 15)),
                'socket_timeout' => max(1, $socketTimeout ?? (int) config('vpnpanel.mikrotik.op_timeout', 60)),
                'throw_timeout_exception' => (bool) config('vpnpanel.mikrotik.throw_timeout_exception', PHP_VERSION_ID < 80400),
                'attempts' => max(1, $attempts ?? $this->retryAttempts()),
                'delay' => 1,
            ]));
        } catch (ConnectException|ClientException $exception) {
            throw new RemoteConnectionException(
                'Unable to connect to MikroTik server: '.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function mutateSecret(Server $server, string $action, array $attributes): array
    {
        return $this->execute($server, '/ppp/secret/'.$action, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function execute(Server $server, string $path, array $attributes = []): array
    {
        return $this->withPooledClient($server, function (Client $client) use ($path, $attributes) {
            $query = new Query($path);

            foreach ($attributes as $key => $value) {
                if ($value === null) {
                    continue;
                }

                $query->equal((string) $key, (string) $value);
            }

            $raw = $client->query($query)->read(false);
            if (! is_array($raw)) {
                $raw = [];
            }

            $raw = array_values(array_filter(
                $raw,
                static fn ($line) => $line !== '' && $line !== null
            ));
            $this->guardAgainstRouterOsTrap($raw, $path);

            if (! in_array('!re', $raw, true)) {
                foreach ($raw as $line) {
                    if (is_string($line) && str_starts_with($line, '=ret=')) {
                        return ['ret' => substr($line, 5)];
                    }
                }

                return [];
            }

            $rows = [];
            $buffer = [];

            foreach ($raw as $line) {
                if ($line === '!re') {
                    if ($buffer !== []) {
                        $parsed = $client->parseResponse(array_merge(['!re'], $buffer));
                        if (isset($parsed[0]) && is_array($parsed[0])) {
                            $rows[] = $parsed[0];
                        }
                    }
                    $buffer = [];

                    continue;
                }

                if ($line !== '!done') {
                    $buffer[] = $line;
                }
            }

            if ($buffer !== []) {
                $parsed = $client->parseResponse(array_merge(['!re'], $buffer));
                if (isset($parsed[0]) && is_array($parsed[0])) {
                    $rows[] = $parsed[0];
                }
            }

            return $rows[0] ?? [];
        }, 'mikrotik.execute', [
            'path' => $path,
        ]);
    }

    /**
     * @param  list<string>  $raw
     */
    protected function guardAgainstRouterOsTrap(array $raw, string $path): void
    {
        if (! in_array('!trap', $raw, true) && ! in_array('!fatal', $raw, true)) {
            return;
        }

        $message = null;

        foreach ($raw as $line) {
            if (is_string($line) && str_starts_with($line, '=message=')) {
                $message = substr($line, 9);
                break;
            }
        }

        throw new RemoteProvisionException(
            'MikroTik: '.($message ?? "خطا در {$path}")
        );
    }

    protected function isRouterOsDisabled(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['true', 'yes', '1'], true);
    }

    protected function normalizeWireguardKey(string $key): string
    {
        return trim($key);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findSecret(Server $server, string $username): ?array
    {
        $results = $this->withRetry(function () use ($server, $username) {
            $client = $this->connect($server);
            $filter = (new Query('/ppp/secret/print'))->where('name', $username);

            return $client->query($filter)->read();
        }, 'mikrotik.find_secret', ['server_id' => $server->id, 'username' => $username]);

        foreach ($results as $row) {
            if (($row['name'] ?? null) === $username) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findWireguardPeer(Server $server, string $publicKey): ?array
    {
        $needle = $this->normalizeWireguardKey($publicKey);

        if ($needle === '') {
            return null;
        }

        $results = $this->withRetry(function () use ($server) {
            $client = $this->connect($server);

            return $client->query('/interface/wireguard/peers/print')->read();
        }, 'mikrotik.find_wireguard_peer', ['server_id' => $server->id]);

        foreach ($results as $row) {
            $candidate = $this->normalizeWireguardKey((string) ($row['public-key'] ?? ''));

            if ($candidate !== '' && hash_equals($candidate, $needle)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{rx_bytes: int, tx_bytes: int}
     */
    protected function readInterfaceStats(Server $server, string $interfaceName): array
    {
        $results = $this->withRetry(function () use ($server, $interfaceName) {
            $client = $this->connect($server);
            $filter = (new Query('/interface/print'))->where('name', $interfaceName);

            return $client->query($filter)->read();
        }, 'mikrotik.interface_stats', ['server_id' => $server->id, 'interface' => $interfaceName]);

        $row = $results[0] ?? [];

        return [
            'rx_bytes' => (int) ($row['rx-byte'] ?? $row['rx-bytes'] ?? 0),
            'tx_bytes' => (int) ($row['tx-byte'] ?? $row['tx-bytes'] ?? 0),
        ];
    }

    protected function setSecretDisabled(Server $server, string $username, bool $disabled): void
    {
        $username = trim($username);

        if ($username === '') {
            return;
        }

        $secret = $this->findSecret($server, $username);

        if ($secret === null) {
            return;
        }

        $this->execute($server, '/ppp/secret/set', [
            '.id' => $secret['.id'],
            'disabled' => $disabled ? 'yes' : 'no',
        ]);
    }
}
