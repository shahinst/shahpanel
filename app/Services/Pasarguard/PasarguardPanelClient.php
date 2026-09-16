<?php

namespace App\Services\Pasarguard;

use App\Exceptions\RemoteConnectionException;
use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HTTP client for PasarGuard Panel REST API (OpenAPI v1.11+).
 *
 * @see https://docs.pasarguard.org/en/panel/
 */
final class PasarguardPanelClient
{
    /** @var array<int, PasarguardPanelUrl> */
    protected static array $resolvedUrlByServer = [];

    /** @var array<int, string> */
    protected static array $tokenByServer = [];

    /** @var list<array<string, mixed>> */
    protected array $debugLog = [];

    protected ?PasarguardPanelUrl $activeUrl = null;

    protected ?string $accessToken = null;

    public function __construct(protected Server $server) {}

    public function url(): PasarguardPanelUrl
    {
        return $this->activeUrl ?? self::$resolvedUrlByServer[$this->server->id] ?? PasarguardPanelUrl::fromServer($this->server);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function debugLog(): array
    {
        return $this->debugLog;
    }

    /**
     * @return array{ok: bool, message: string, error?: string, panel_url?: string, admin_username?: string, panel_version?: string, inbound_count?: int, group_count?: int, user_count?: int, permissions?: array<string, bool>, debug?: list<array<string, mixed>>}
     */
    public function testConnection(): array
    {
        $this->debugLog = [];
        $tried = [];
        $testTimeout = max(15, (int) config('shahpanel.pasarguard.test_timeout_seconds', 60));
        $testConnect = max(10, (int) config('shahpanel.pasarguard.test_connect_timeout_seconds', 30));

        try {
            $this->resolveReachableUrl($tried);
            $this->authenticate($testTimeout, $testConnect);
            $this->logStep('authenticated', [
                'panel_url' => $this->url()->displayAddress(),
                'api_url' => $this->url()->apiBaseUrl(),
            ]);

            $admin = $this->testFetchStep('admin', fn (): array => $this->getCurrentAdmin(), $testTimeout, $testConnect, required: true);
            $system = $this->testFetchStep('system', fn (): array => $this->getSystem($testTimeout), $testTimeout, $testConnect);
            $inbounds = $this->testFetchStep('inbounds', fn (): array => $this->listInboundTags(), $testTimeout, $testConnect) ?? [];
            $groups = $this->testFetchStep('groups', fn (): array => $this->listGroups(), $testTimeout, $testConnect) ?? [];
            $permissions = $this->probePermissions($testTimeout, $testConnect);

            $userCount = null;
            try {
                $usersPayload = $this->getUsers(limit: 1, timeoutSeconds: $testTimeout);
                $userCount = (int) ($usersPayload['total'] ?? count($usersPayload['users'] ?? []));
            } catch (Throwable $exception) {
                $permissions['users.read'] = false;
                $this->logStep('users_probe_failed', ['error' => $exception->getMessage()]);
            }

            $warnings = $this->collectTestWarnings();
            $message = 'اتصال به پنل PasarGuard برقرار شد.';
            if ($warnings !== []) {
                $message .= ' (برخی endpointها کند بودند یا timeout خوردند — عملیات کاربران معمولاً کار می‌کند.)';
            }

            $normalizedGroups = PasarguardGroupCatalog::normalizeList(is_array($groups) ? $groups : []);

            $result = [
                'ok' => true,
                'message' => $message,
                'panel_url' => $this->url()->displayAddress(),
                'api_url' => $this->url()->apiBaseUrl(),
                'admin_username' => (string) ($admin['username'] ?? ''),
                'panel_version' => (string) ($system['version'] ?? ''),
                'inbound_count' => is_array($inbounds) ? count($inbounds) : 0,
                'group_count' => count($normalizedGroups),
                'groups' => $normalizedGroups,
                'user_count' => $userCount,
                'permissions' => $permissions,
                'warnings' => $warnings,
                'debug' => $this->debugLog,
            ];

            $this->writeLog('info', 'PasarGuard connection test succeeded', $result);

            return $result;
        } catch (Throwable $exception) {
            $result = [
                'ok' => false,
                'message' => __('services.pasarguard_connect_failed'),
                'error' => $exception->getMessage(),
                'panel_url' => $this->url()->displayAddress(),
                'api_url' => $this->url()->apiBaseUrl(),
                'tried_urls' => $tried,
                'debug' => $this->debugLog,
            ];

            $this->writeLog('warning', 'PasarGuard connection test failed', $result);

            return $result;
        }
    }

    public function authenticate(?int $timeoutSeconds = null, ?int $connectTimeoutSeconds = null): void
    {
        if ($this->accessToken !== null) {
            return;
        }

        if ($this->server->api_token_enc) {
            $this->accessToken = $this->server->api_token_enc;
            $this->resolveReachableUrl();

            return;
        }

        if ($this->server->username_enc === null || $this->server->password_enc === null) {
            throw new RemoteConnectionException(
                __('services.pasarguard_credentials_missing', ['id' => $this->server->id])
            );
        }

        $this->resolveReachableUrl();

        // resolveReachableUrl() restores a token cached for this server; without
        // this check every new client instance logs in again needlessly.
        if ($this->accessToken !== null) {
            return;
        }

        $this->loginWithPassword(
            $this->server->username_enc,
            $this->server->password_enc,
            $timeoutSeconds,
            $connectTimeoutSeconds,
        );
    }

    // ------------------------------------------------------------------
    // Node management (sudo / full admin)
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getNodes(
        ?int $offset = null,
        ?int $limit = null,
        ?bool $enabled = null,
        ?string $search = null
    ): array {
        $query = array_filter([
            'offset' => $offset,
            'limit' => $limit,
            'enabled' => $enabled,
            'search' => $search,
        ], fn ($v) => $v !== null);

        return $this->decodeJson($this->apiGet('nodes', $query));
    }

    /**
     * @return array<string, mixed>
     */
    public function getNode(int $nodeId): array
    {
        return $this->decodeJson($this->apiGet('node/'.$nodeId));
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getCurrentAdmin(): array
    {
        return $this->decodeJson($this->apiGet('admin'));
    }

    /**
     * @return array<string, mixed>
     */
    public function getAdmins(?string $username = null, ?int $offset = null, ?int $limit = null): array
    {
        $query = array_filter([
            'username' => $username,
            'offset' => $offset,
            'limit' => $limit,
        ], fn ($v) => $v !== null);

        return $this->decodeJson($this->apiGet('admins', $query));
    }

    // ------------------------------------------------------------------
    // System / settings / core
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getSystem(?int $timeoutSeconds = null): array
    {
        return $this->decodeJson($this->apiGet('system', timeoutSeconds: $timeoutSeconds));
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->decodeJson($this->apiGet('settings'));
    }

    // ------------------------------------------------------------------
    // Inbounds / groups / hosts
    // ------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public function listInboundTags(): array
    {
        $payload = $this->decodeJson($this->apiGet('inbounds'));

        if (! is_array($payload)) {
            return [];
        }

        // Some PasarGuard versions answer with inbound objects rather than tags;
        // strval() on a nested array is a fatal TypeError in PHP 8.
        return array_values(array_filter(array_map(
            static fn ($tag): string => is_scalar($tag) ? (string) $tag : '',
            $payload
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listGroups(): array
    {
        $payload = $this->decodeJson($this->apiGet('groups'));
        $groups = $payload['groups'] ?? $payload;

        return is_array($groups) ? $groups : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listHosts(): array
    {
        $payload = $this->decodeJson($this->apiGet('hosts'));
        $hosts = $payload['hosts'] ?? $payload;

        return is_array($hosts) ? $hosts : [];
    }

    // ------------------------------------------------------------------
    // Users
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getUsers(
        ?int $offset = null,
        ?int $limit = null,
        ?string $username = null,
        ?string $search = null,
        ?int $timeoutSeconds = null,
    ): array {
        $query = array_filter([
            'offset' => $offset,
            'limit' => $limit,
            'username' => $username,
            'search' => $search,
        ], fn ($v) => $v !== null);

        return $this->decodeJson($this->apiGet('users', $query, timeoutSeconds: $timeoutSeconds));
    }

    /**
     * @return array<string, mixed>
     */
    public function getUser(string $username): array
    {
        return $this->decodeJson($this->apiGet('user/'.rawurlencode($username)));
    }

    /**
     * Historical traffic stats for a user (PasarGuard `/user/{username}/usage`).
     *
     * @return array<string, mixed>
     */
    public function getUserUsage(
        string $username,
        ?string $start = null,
        ?string $end = null,
        string $period = 'day',
        ?int $nodeId = null,
    ): array {
        $query = array_filter([
            'start' => $start,
            'end' => $end,
            'period' => $period,
            'node_id' => $nodeId,
        ], static fn ($value): bool => $value !== null && $value !== '');

        return $this->decodeJson($this->apiGet('user/'.rawurlencode($username).'/usage', $query));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createUser(array $payload): array
    {
        return $this->decodeJson($this->apiPost('user', $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function modifyUser(string $username, array $payload): array
    {
        return $this->decodeJson($this->apiPut('user/'.rawurlencode($username), $payload));
    }

    public function removeUser(string $username): void
    {
        $response = $this->apiDelete('user/'.rawurlencode($username));

        // A user that is already gone counts as removed; every other failure
        // (401/403/5xx) must surface instead of being reported as success.
        if ($response->status() === 404) {
            return;
        }

        $this->decodeJson($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function resetUserTraffic(string $username): array
    {
        return $this->decodeJson($this->apiPost('user/'.rawurlencode($username).'/reset'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUserTemplates(): array
    {
        $payload = $this->decodeJson($this->apiGet('user_templates'));

        return is_array($payload) ? $payload : [];
    }

    // ------------------------------------------------------------------
    // HTTP layer
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $query
     */
    public function apiGet(string $path, array $query = [], ?int $timeoutSeconds = null): Response
    {
        return $this->apiRequest('GET', $path, query: $query, timeoutSeconds: $timeoutSeconds);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function apiPost(string $path, array $payload = [], ?int $timeoutSeconds = null): Response
    {
        return $this->apiRequest('POST', $path, payload: $payload, timeoutSeconds: $timeoutSeconds);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function apiPut(string $path, array $payload = [], ?int $timeoutSeconds = null): Response
    {
        return $this->apiRequest('PUT', $path, payload: $payload, timeoutSeconds: $timeoutSeconds);
    }

    public function apiDelete(string $path, ?int $timeoutSeconds = null): Response
    {
        return $this->apiRequest('DELETE', $path, timeoutSeconds: $timeoutSeconds);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $payload
     */
    public function apiRequest(
        string $method,
        string $path,
        array $query = [],
        array $payload = [],
        ?int $timeoutSeconds = null,
        ?int $connectTimeoutSeconds = null,
        bool $allowReauth = true,
    ): Response {
        $this->authenticate($timeoutSeconds, $connectTimeoutSeconds);

        $client = $this->http($timeoutSeconds, $connectTimeoutSeconds);
        $url = $this->url()->api($path);

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $client = $client->withToken($this->accessToken ?? '');

        try {
            $response = match (strtoupper($method)) {
                'GET' => $client->get($url),
                'POST' => $client->asJson()->post($url, $payload),
                'PUT' => $client->asJson()->put($url, $payload),
                'DELETE' => $client->delete($url),
                default => throw new RemoteConnectionException(__('services.http_method_unsupported', ['method' => $method])),
            };
        } catch (ConnectionException $exception) {
            throw new RemoteConnectionException($this->friendlyConnectionError($exception->getMessage()), 0, $exception);
        }

        // A long-lived worker outlives the admin JWT; re-login once instead of
        // turning every later call into an unrecoverable 401.
        if ($response->status() === 401 && $allowReauth && ! $this->server->api_token_enc) {
            $this->accessToken = null;
            unset(self::$tokenByServer[$this->server->id]);

            $this->authenticate($timeoutSeconds, $connectTimeoutSeconds);

            return $this->apiRequest($method, $path, $query, $payload, $timeoutSeconds, $connectTimeoutSeconds, false);
        }

        return $response;
    }

    protected function loginWithPassword(
        string $username,
        string $password,
        ?int $timeoutSeconds = null,
        ?int $connectTimeoutSeconds = null,
    ): void {
        $url = $this->url()->api('admin/token');
        $response = $this->http($timeoutSeconds, $connectTimeoutSeconds)->asForm()->post($url, [
            'username' => $username,
            'password' => $password,
            'grant_type' => 'password',
        ]);

        $this->logStep('login', [
            'url' => $url,
            'status' => $response->status(),
            'success' => $response->successful(),
        ]);

        if (! $response->successful()) {
            throw new RemoteConnectionException(
                __('services.pasarguard_login_failed', ['status' => $response->status(), 'detail' => $this->errorDetail($response)])
            );
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RemoteConnectionException(__('services.pasarguard_login_no_token'));
        }

        $this->accessToken = $token;
        self::$tokenByServer[$this->server->id] = $token;
    }

    /**
     * Which privileged endpoints this credential can access (for UI hints).
     *
     * @return array<string, bool>
     */
    protected function probePermissions(?int $timeoutSeconds = null, ?int $connectTimeoutSeconds = null): array
    {
        $checks = [
            'nodes.read' => ['GET', 'nodes'],
            'settings.read' => ['GET', 'settings'],
            'hosts.read' => ['GET', 'hosts'],
            'users.read' => ['GET', 'users', ['limit' => 1]],
        ];

        $out = [];
        foreach ($checks as $key => $spec) {
            $method = $spec[0];
            $path = $spec[1];
            $query = $spec[2] ?? [];
            try {
                $response = $this->apiRequest(
                    $method,
                    $path,
                    query: $query,
                    timeoutSeconds: $timeoutSeconds,
                    connectTimeoutSeconds: $connectTimeoutSeconds,
                );
                $out[$key] = $response->successful();
            } catch (Throwable $exception) {
                // A timeout is not a permission denial. Record it as a warning so
                // the admin is not sent granting permissions that already exist.
                $out[$key] = false;
                $this->logStep($key.'_probe_failed', ['error' => $exception->getMessage()]);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $tried
     */
    protected function resolveReachableUrl(array &$tried = []): void
    {
        if ($this->activeUrl !== null) {
            return;
        }

        if (isset(self::$resolvedUrlByServer[$this->server->id])) {
            $this->activeUrl = self::$resolvedUrlByServer[$this->server->id];
            if (isset(self::$tokenByServer[$this->server->id])) {
                $this->accessToken = self::$tokenByServer[$this->server->id];
            }

            return;
        }

        $lastError = 'آدرس پنل PasarGuard در دسترس نیست.';

        foreach (PasarguardPanelUrl::candidatesFromServer($this->server) as $candidate) {
            $tried[] = $candidate->apiBaseUrl();
            $probeUrl = $candidate->api('admin/token');
            $probeConnect = max(10, (int) config('shahpanel.pasarguard.test_connect_timeout_seconds', 30));

            try {
                $response = Http::timeout($probeConnect)
                    ->connectTimeout($probeConnect)
                    ->withOptions($this->httpCurlOptions())
                    ->withHeaders(['Accept' => 'application/json'])
                    ->send('OPTIONS', $probeUrl);

                // 405 Method Not Allowed still proves the API route exists.
                if (in_array($response->status(), [200, 401, 405], true)) {
                    $this->activeUrl = $candidate;
                    self::$resolvedUrlByServer[$this->server->id] = $candidate;
                    $this->logStep('url_resolved', [
                        'panel_url' => $candidate->displayAddress(),
                        'api_url' => $candidate->apiBaseUrl(),
                    ]);

                    return;
                }

                $lastError = 'HTTP '.$response->status().' برای '.$probeUrl;
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
                $this->logStep('url_probe_failed', [
                    'url' => $probeUrl,
                    'error' => $lastError,
                ]);
            }
        }

        throw new RemoteConnectionException($lastError.' — '.__('services.panel_urls_tried', ['list' => implode(' | ', $tried)]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJson(Response $response): array
    {
        if (! $response->successful()) {
            throw new RemoteConnectionException(
                'HTTP '.$response->status().': '.$this->errorDetail(
                    $response,
                    $response->status() === 403 ? 'Permission denied' : null
                )
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Upstream error text, capped — an HTML error page must not be embedded
     * verbatim in an exception message that is logged and shown to the admin.
     */
    protected function errorDetail(Response $response, ?string $fallback = null): string
    {
        $detail = $response->json('detail') ?? $fallback ?? $response->body();

        if (! is_string($detail)) {
            $detail = (string) json_encode($detail, JSON_UNESCAPED_UNICODE);
        }

        return mb_substr(trim($detail), 0, 300);
    }

    protected function http(?int $timeoutSeconds = null, ?int $connectTimeoutSeconds = null): \Illuminate\Http\Client\PendingRequest
    {
        $timeout = $timeoutSeconds ?? (int) config('shahpanel.pasarguard.timeout_seconds', 45);
        $connect = $connectTimeoutSeconds ?? (int) config('shahpanel.pasarguard.connect_timeout_seconds', 25);

        return Http::timeout($timeout)
            ->connectTimeout($connect)
            ->withOptions($this->httpCurlOptions())
            ->withHeaders(['Accept' => 'application/json']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function httpCurlOptions(): array
    {
        return [
            'verify' => config('shahpanel.pasarguard.verify_ssl', true),
            'curl' => [
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE => 60,
                CURLOPT_TCP_KEEPINTVL => 10,
            ],
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    protected function testFetchStep(
        string $step,
        callable $callback,
        int $timeoutSeconds,
        int $connectTimeoutSeconds,
        bool $required = false,
    ): mixed {
        try {
            $result = $callback();
            $this->logStep($step.'_ok', []);

            return $result;
        } catch (Throwable $exception) {
            $this->logStep($step.'_failed', ['error' => $exception->getMessage()]);

            if ($required) {
                throw $exception;
            }

            return null;
        }
    }

    /**
     * @return list<string>
     */
    protected function collectTestWarnings(): array
    {
        $warnings = [];

        foreach ($this->debugLog as $entry) {
            $step = (string) ($entry['step'] ?? '');
            if (str_ends_with($step, '_failed')) {
                $warnings[] = $step.': '.(string) ($entry['error'] ?? 'خطا');
            }
        }

        return $warnings;
    }

    protected function friendlyConnectionError(string $message): string
    {
        if (str_contains(strtolower($message), 'timed out')) {
            return 'اتصال به پنل PasarGuard زمان‌بر شد — آدرس، پورت و فایروال را بررسی کنید.';
        }

        if (str_contains(strtolower($message), 'could not resolve host')) {
            return 'نام میزبان PasarGuard قابل resolve نیست.';
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logStep(string $step, array $context = []): void
    {
        $this->debugLog[] = array_merge(['step' => $step, 'at' => now()->toIso8601String()], $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function writeLog(string $level, string $message, array $context): void
    {
        Log::channel('pasarguard')->{$level}($message, array_merge([
            'server_id' => $this->server->id,
            'host' => $this->server->host,
        ], $context));
    }
}
