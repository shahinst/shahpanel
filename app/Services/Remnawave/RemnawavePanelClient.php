<?php

namespace App\Services\Remnawave;

use App\Exceptions\RemoteConnectionException;
use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HTTP client for the Remnawave panel REST API (v2.x / v3.x, prefix /api).
 *
 * All panel responses are wrapped in a `{ "response": ... }` envelope which
 * this client unwraps automatically. Empty 204 bodies (common in v3 deletes)
 * decode to an empty array.
 *
 * User identity: v2 uses string UUID; v3 uses numeric id. Path segment is
 * `/users/{userId}` on both — pass whichever the panel expects.
 *
 * @see https://remna.st/
 */
final class RemnawavePanelClient
{
    /** @var array<int, RemnawavePanelUrl> */
    protected static array $resolvedUrlByServer = [];

    /** @var array<int, string> */
    protected static array $tokenByServer = [];

    /** @var list<array<string, mixed>> */
    protected array $debugLog = [];

    protected ?RemnawavePanelUrl $activeUrl = null;

    protected ?string $accessToken = null;

    public function __construct(protected Server $server) {}

    public function url(): RemnawavePanelUrl
    {
        return $this->activeUrl
            ?? self::$resolvedUrlByServer[$this->server->id]
            ?? RemnawavePanelUrl::fromServer($this->server);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function debugLog(): array
    {
        return $this->debugLog;
    }

    /**
     * @return array{ok: bool, message: string, error?: string, panel_url?: string, api_url?: string, panel_version?: string, inbound_count?: int, squad_count?: int, squads?: list<array{uuid: string, name: string}>, user_count?: int, warnings?: list<string>, debug?: list<array<string, mixed>>}
     */
    public function testConnection(): array
    {
        $this->debugLog = [];
        $tried = [];
        $testTimeout = max(15, (int) config('shahpanel.remnawave.test_timeout_seconds', 60));
        $testConnect = max(10, (int) config('shahpanel.remnawave.test_connect_timeout_seconds', 30));

        try {
            $this->resolveReachableUrl($tried);
            $this->authenticate($testTimeout, $testConnect);
            $this->logStep('authenticated', [
                'panel_url' => $this->url()->displayAddress(),
                'api_url' => $this->url()->apiBaseUrl(),
            ]);

            $status = $this->testFetchStep('status', fn (): array => $this->getStatus($testTimeout), $testTimeout, $testConnect) ?? [];

            $squads = [];
            $squadCount = 0;
            try {
                $rawSquads = $this->testFetchStep(
                    'squads',
                    fn (): array => $this->listSquads(),
                    $testTimeout,
                    $testConnect,
                ) ?? [];
                $squads = RemnawaveSquadCatalog::normalizeList($rawSquads);
                $squadCount = count($squads);
                $this->logStep('squads_ok', ['count' => $squadCount]);
            } catch (Throwable $exception) {
                $this->logStep('squads_probe_failed', ['error' => $exception->getMessage()]);
            }

            $nodes = [];
            $nodeCount = 0;
            try {
                $rawNodes = $this->testFetchStep(
                    'nodes',
                    fn (): array => $this->listNodes(),
                    $testTimeout,
                    $testConnect,
                ) ?? [];
                $nodes = RemnawaveNodeCatalog::normalizeList($rawNodes);
                $nodeCount = count($nodes);
                $this->logStep('nodes_ok', ['count' => $nodeCount]);
            } catch (Throwable $exception) {
                $this->logStep('nodes_probe_failed', ['error' => $exception->getMessage()]);
            }

            $inboundCount = null;
            try {
                $inbounds = $this->testFetchStep(
                    'inbounds',
                    fn (): array => $this->listInbounds(),
                    $testTimeout,
                    $testConnect,
                ) ?? [];
                $inboundCount = count($inbounds);
                $this->logStep('inbounds_ok', ['count' => $inboundCount]);
            } catch (Throwable $exception) {
                $this->logStep('inbounds_probe_failed', ['error' => $exception->getMessage()]);
            }

            $userCount = null;
            try {
                $usersPayload = $this->getUsers(size: 1, start: 0, timeoutSeconds: $testTimeout);
                $userCount = (int) ($usersPayload['total'] ?? count($usersPayload['users'] ?? []));
                $this->logStep('users_ok', ['total' => $userCount]);
            } catch (Throwable $exception) {
                $this->logStep('users_probe_failed', ['error' => $exception->getMessage()]);
            }

            $warnings = $this->collectTestWarnings();

            if ($userCount === null) {
                $probeError = $this->usersProbeFailureMessage();
                if ($probeError !== null) {
                    return [
                        'ok' => false,
                        'message' => $probeError,
                        'error' => $probeError,
                        'panel_url' => $this->url()->displayAddress(),
                        'api_url' => $this->url()->apiBaseUrl(),
                        'warnings' => $warnings,
                        'debug' => $this->debugLog,
                    ];
                }
            }

            $message = 'اتصال به پنل Remnawave برقرار شد.';
            if ($squadCount > 0) {
                $message .= ' — '.persian_digits($squadCount).' Internal Squad (ذخیره شد).';
            }
            if ($nodeCount > 0) {
                $message .= ' — '.persian_digits($nodeCount).' node.';
            }
            if ($inboundCount !== null && $inboundCount > 0) {
                $message .= ' — '.persian_digits($inboundCount).' inbound.';
            }
            if ($userCount !== null) {
                $message .= ' — '.persian_digits($userCount).' کاربر در پنل.';
            }

            $result = [
                'ok' => true,
                'message' => $message,
                'panel_url' => $this->url()->displayAddress(),
                'api_url' => $this->url()->apiBaseUrl(),
                'panel_version' => self::scalarString($status['version'] ?? null),
                'inbound_count' => $inboundCount,
                'squad_count' => $squadCount,
                'squads' => $squads,
                'node_count' => $nodeCount,
                'nodes' => $nodes,
                'user_count' => $userCount,
                'warnings' => $warnings,
                'debug' => $this->debugLog,
            ];

            $this->writeLog('info', 'Remnawave connection test succeeded', $result);

            return $result;
        } catch (Throwable $exception) {
            $result = [
                'ok' => false,
                'message' => __('services.remnawave_connect_failed'),
                'error' => $exception->getMessage(),
                'panel_url' => $this->url()->displayAddress(),
                'api_url' => $this->url()->apiBaseUrl(),
                'tried_urls' => $tried,
                'debug' => $this->debugLog,
            ];

            $this->writeLog('warning', 'Remnawave connection test failed', $result);

            return $result;
        }
    }

    public static function clearAuthCacheForServer(int $serverId): void
    {
        unset(self::$tokenByServer[$serverId], self::$resolvedUrlByServer[$serverId]);
    }

    public function authenticate(?int $timeoutSeconds = null, ?int $connectTimeoutSeconds = null): void
    {
        if ($this->accessToken !== null) {
            return;
        }

        $this->resolveReachableUrl();

        $bearer = $this->resolveBearerToken();
        if ($bearer === null) {
            self::clearAuthCacheForServer($this->server->id);

            throw new RemoteConnectionException($this->missingApiTokenMessage());
        }

        $this->accessToken = $bearer;
        self::$tokenByServer[$this->server->id] = $bearer;
        $this->logStep('auth', ['method' => 'api_token']);
    }

    // ------------------------------------------------------------------
    // System / status
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getStatus(?int $timeoutSeconds = null): array
    {
        return $this->decodeJson($this->apiGet('auth/status', timeoutSeconds: $timeoutSeconds));
    }

    // ------------------------------------------------------------------
    // Inbounds / squads
    // ------------------------------------------------------------------

    /**
     * Inbounds (v2.x under config-profiles, v1.x fallback to /inbounds).
     *
     * @return list<array<string, mixed>>
     */
    public function listInbounds(): array
    {
        $errors = [];

        foreach ([
            'config-profiles/inbounds',
            'inbounds',
        ] as $path) {
            try {
                $payload = $this->decodeJson($this->apiGet($path));
                $inbounds = $payload['inbounds'] ?? $payload;

                if (is_array($inbounds)) {
                    $list = array_values(array_filter($inbounds, 'is_array'));
                    if ($list !== []) {
                        return $list;
                    }
                }
            } catch (Throwable $exception) {
                $errors[] = $path.': '.$exception->getMessage();
            }
        }

        try {
            return $this->listInboundsFromProfiles();
        } catch (Throwable $exception) {
            $errors[] = 'config-profiles: '.$exception->getMessage();
        }

        throw new RemoteConnectionException(
            __('services.remnawave_inbounds_failed', ['detail' => implode(' | ', $errors)])
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function listInboundsFromProfiles(): array
    {
        $payload = $this->decodeJson($this->apiGet('config-profiles'));
        $profiles = $payload['configProfiles'] ?? $payload['profiles'] ?? $payload;
        if (! is_array($profiles)) {
            return [];
        }

        $merged = [];
        $seen = [];

        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $uuid = self::scalarString($profile['uuid'] ?? null);
            if ($uuid !== '') {
                try {
                    $detail = $this->decodeJson($this->apiGet('config-profiles/'.rawurlencode($uuid).'/inbounds'));
                    $batch = $detail['inbounds'] ?? $detail;
                    if (is_array($batch)) {
                        foreach (array_values(array_filter($batch, 'is_array')) as $inbound) {
                            $key = self::inboundKey($inbound);
                            if (! isset($seen[$key])) {
                                $seen[$key] = true;
                                $merged[] = $inbound;
                            }
                        }
                    }
                } catch (Throwable) {
                    // try embedded inbounds on profile object
                }
            }

            $embedded = $profile['inbounds'] ?? null;
            if (is_array($embedded)) {
                foreach (array_values(array_filter($embedded, 'is_array')) as $inbound) {
                    $key = self::inboundKey($inbound);
                    if (! isset($seen[$key])) {
                        $seen[$key] = true;
                        $merged[] = $inbound;
                    }
                }
            }
        }

        return $merged;
    }

    /**
     * Internal squads ({uuid, name}) used in activeInternalSquads.
     *
     * @return list<array<string, mixed>>
     */
    public function listSquads(): array
    {
        $payload = $this->decodeJson($this->apiGet('internal-squads'));
        $squads = $payload['internalSquads'] ?? $payload['squads'] ?? $payload;

        return is_array($squads) ? array_values(array_filter($squads, 'is_array')) : [];
    }

    /**
     * VPN nodes (GET /api/nodes).
     *
     * @return list<array<string, mixed>>
     */
    public function listNodes(): array
    {
        $payload = $this->decodeJson($this->apiGet('nodes'));
        $nodes = $payload['nodes'] ?? $payload;

        return is_array($nodes) ? array_values(array_filter($nodes, 'is_array')) : [];
    }

    // ------------------------------------------------------------------
    // Users
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getUsers(?int $size = null, ?int $start = null, ?int $timeoutSeconds = null): array
    {
        $query = array_filter([
            'size' => $size,
            'start' => $start,
        ], fn ($v) => $v !== null);

        return $this->decodeJson($this->apiGet('users', $query, timeoutSeconds: $timeoutSeconds));
    }

    /**
     * Fetch a user by panel identity (v2 UUID or v3 numeric id).
     *
     * @return array<string, mixed>
     */
    public function getUserByUuid(string $uuid): array
    {
        return $this->getUserById($uuid);
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserById(string $userId): array
    {
        return $this->decodeJson($this->apiGet('users/'.rawurlencode($userId)));
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserByUsername(string $username): array
    {
        return $this->decodeJson($this->apiGet('users/by-username/'.rawurlencode($username)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createUser(array $payload): array
    {
        // Trailing slash is required by the Remnawave create endpoint.
        return $this->decodeJson($this->apiPost('users/', $payload));
    }

    /**
     * Modify a user. Payload must identify the user via `username` (v2+v3),
     * `id` (v3), or `uuid` (v2).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function modifyUser(array $payload): array
    {
        return $this->decodeJson($this->apiPatch('users', $payload));
    }

    public function removeUser(string $userId): void
    {
        // v3 often returns 204 with an empty body.
        $this->decodeJson($this->apiDelete('users/'.rawurlencode($userId)));
    }

    /**
     * @return array<string, mixed>
     */
    public function enableUser(string $userId): array
    {
        return $this->decodeJson($this->apiPost('users/'.rawurlencode($userId).'/actions/enable'));
    }

    /**
     * @return array<string, mixed>
     */
    public function disableUser(string $userId): array
    {
        return $this->decodeJson($this->apiPost('users/'.rawurlencode($userId).'/actions/disable'));
    }

    /**
     * @return array<string, mixed>
     */
    public function resetUserTraffic(string $userId): array
    {
        return $this->decodeJson($this->apiPost('users/'.rawurlencode($userId).'/actions/reset-traffic'));
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
    public function apiPatch(string $path, array $payload = [], ?int $timeoutSeconds = null): Response
    {
        return $this->apiRequest('PATCH', $path, payload: $payload, timeoutSeconds: $timeoutSeconds);
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
    ): Response {
        $this->authenticate($timeoutSeconds, $connectTimeoutSeconds);

        $client = $this->http($timeoutSeconds, $connectTimeoutSeconds)->withToken($this->accessToken ?? '');
        $url = $this->url()->api($path);

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        try {
            return match (strtoupper($method)) {
                'GET' => $client->get($url),
                'POST' => $client->asJson()->post($url, $payload),
                'PATCH' => $client->asJson()->patch($url, $payload),
                'PUT' => $client->asJson()->put($url, $payload),
                'DELETE' => $client->delete($url),
                default => throw new RemoteConnectionException(__('services.http_method_unsupported', ['method' => $method])),
            };
        } catch (ConnectionException $exception) {
            throw new RemoteConnectionException($this->friendlyConnectionError($exception->getMessage()), 0, $exception);
        }
    }

    /**
     * Remnawave authenticates with an API token only — there is no
     * username/password login path.
     */
    protected static function scalarString(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $inbound
     */
    protected static function inboundKey(array $inbound): string
    {
        $key = self::scalarString($inbound['uuid'] ?? null)
            ?: self::scalarString($inbound['tag'] ?? null);

        return $key !== '' ? $key : md5((string) json_encode($inbound));
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

            return;
        }

        $lastError = 'آدرس پنل Remnawave در دسترس نیست.';

        foreach (RemnawavePanelUrl::candidatesFromServer($this->server) as $candidate) {
            $tried[] = $candidate->apiBaseUrl();
            $probeUrl = $candidate->api('auth/status');
            $probeConnect = max(10, (int) config('shahpanel.remnawave.test_connect_timeout_seconds', 30));

            try {
                $response = $this->http($probeConnect, $probeConnect)->get($probeUrl);

                if (in_array($response->status(), [200, 401, 403, 405], true)) {
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
            $detail = $response->json('message') ?? $response->json('error') ?? $response->body();
            $detailStr = is_string($detail) ? $detail : (string) json_encode($detail, JSON_UNESCAPED_UNICODE);

            // Cap it: an HTML error page must not be embedded verbatim in an
            // exception that is logged and rendered to the admin.
            throw new RemoteConnectionException(
                'HTTP '.$response->status().': '.mb_substr(trim($detailStr), 0, 300)
            );
        }

        // v3 DELETE / some actions return 204 No Content (or empty 200).
        $raw = trim((string) $response->body());
        if ($raw === '' || $response->status() === 204) {
            return [];
        }

        $json = $response->json();
        if (! is_array($json)) {
            // A non-empty body that is not JSON is a proxy/Cloudflare page, not
            // a result. Returning [] here made a failed create look successful.
            throw new RemoteConnectionException(
                __('services.remnawave_invalid_json', [
                    'status' => $response->status(),
                    'type' => self::scalarString($response->header('Content-Type'), 'unknown'),
                ])
            );
        }

        // Unwrap the `{ "response": ... }` envelope.
        if (array_key_exists('response', $json)) {
            $inner = $json['response'];

            return is_array($inner) ? $inner : ['value' => $inner];
        }

        return $json;
    }

    protected function http(?int $timeoutSeconds = null, ?int $connectTimeoutSeconds = null): PendingRequest
    {
        $timeout = $timeoutSeconds ?? (int) config('shahpanel.remnawave.timeout_seconds', 45);
        $connect = $connectTimeoutSeconds ?? (int) config('shahpanel.remnawave.connect_timeout_seconds', 25);

        $headers = ['Accept' => 'application/json'];
        $caddyKey = trim((string) ($this->server->remnawave_api_key_enc ?? ''));
        // JWT توکن API را بعضی‌ها اشتباه در فیلد Caddy می‌گذارند — فقط کلید کوتاه Caddy در X-Api-Key.
        if ($caddyKey !== '' && ! $this->looksLikeJwt($caddyKey)) {
            $headers['X-Api-Key'] = $caddyKey;
        }

        return Http::timeout($timeout)
            ->connectTimeout($connect)
            ->withOptions($this->httpCurlOptions())
            ->withHeaders($headers);
    }

    /**
     * @return array<string, mixed>
     */
    protected function httpCurlOptions(): array
    {
        return [
            'verify' => config('shahpanel.remnawave.verify_ssl', true),
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

    protected function resolveBearerToken(): ?string
    {
        $apiToken = trim((string) ($this->server->api_token_enc ?? ''));
        if ($apiToken !== '') {
            return $apiToken;
        }

        $alt = trim((string) ($this->server->remnawave_api_key_enc ?? ''));
        if ($alt !== '' && $this->looksLikeJwt($alt)) {
            return $alt;
        }

        return null;
    }

    protected function looksLikeJwt(string $value): bool
    {
        return str_starts_with($value, 'eyJ') && substr_count($value, '.') >= 2;
    }

    protected function missingApiTokenMessage(): string
    {
        if (trim((string) ($this->server->api_token_enc ?? '')) === '') {
            return 'توکن API در shahpanel ذخیره نشده است. ویرایش سرور → فیلد «توکن API» → توکن از Remnawave Settings → API Tokens را بچسبانید → ذخیره. (نام کاربری/رمز برای ساخت کاربر کافی نیست.)';
        }

        return 'توکن API ذخیره‌شده معتبر نیست یا منقضی شده — در پنل Remnawave توکن جدید بسازید و دوباره در فیلد «توکن API» ذخیره کنید.';
    }

    protected function usersProbeFailureMessage(): ?string
    {
        foreach ($this->debugLog as $entry) {
            if (($entry['step'] ?? '') !== 'users_probe_failed') {
                continue;
            }

            $error = (string) ($entry['error'] ?? '');
            $lower = strtolower($error);

            if (str_contains($lower, 'api-token') || str_contains($lower, 'api token')) {
                return $this->missingApiTokenMessage();
            }

            return $error !== '' ? $error : __('services.remnawave_users_api_failed');
        }

        return null;
    }

    protected function friendlyConnectionError(string $message): string
    {
        if (str_contains(strtolower($message), 'timed out')) {
            return 'اتصال به پنل Remnawave زمان‌بر شد — آدرس، پورت و فایروال را بررسی کنید.';
        }

        if (str_contains(strtolower($message), 'could not resolve host')) {
            return 'نام میزبان Remnawave قابل resolve نیست.';
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
        Log::channel('remnawave')->{$level}($message, array_merge([
            'server_id' => $this->server->id,
            'host' => $this->server->host,
        ], $context));
    }
}
