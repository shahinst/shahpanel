<?php

namespace App\Services\Sanaei;

use App\Exceptions\RemoteConnectionException;
use App\Models\Server;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for MHSanaei 3x-ui / Sanaei panels (multiple API generations).
 */
final class SanaeiPanelClient
{
    /** @var array<int, string> */
    protected static array $apiPrefixByServer = [];

    /** @var array<int, SanaeiPanelUrl> */
    protected static array $resolvedUrlByServer = [];

    /** @var array<string, array{0: string, 1: bool}> */
    protected static array $resolvedPostRouteByServer = [];

    /** @var list<array<string, mixed>> */
    protected array $debugLog = [];

    protected CookieJar $cookieJar;

    protected ?string $sessionCookie = null;

    protected ?string $csrfToken = null;

    protected ?SanaeiPanelUrl $activeUrl = null;

    protected bool $sessionAuthForced = false;

    public function __construct(protected Server $server)
    {
        $this->cookieJar = new CookieJar;
    }

    public function url(): SanaeiPanelUrl
    {
        return $this->activeUrl ?? self::$resolvedUrlByServer[$this->server->id] ?? SanaeiPanelUrl::fromServer($this->server);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function debugLog(): array
    {
        return $this->debugLog;
    }

    /**
     * @return array{ok: bool, message: string, inbound_count?: int, error?: string, panel_url?: string, api_prefix?: string, tried_urls?: list<string>, debug?: list<array<string, mixed>>}
     */
    public function testConnection(): array
    {
        $this->debugLog = [];
        $tried = [];

        try {
            $this->resolveReachableUrl($tried);
            $this->logStep('url_resolved', ['panel_url' => $this->url()->displayAddress()]);

            $this->authenticate();
            $this->logStep('authenticated', [
                'method' => ($this->sessionAuthForced || $this->hasPanelCredentials() || ! $this->server->api_token_enc)
                    ? 'session'
                    : 'bearer',
            ]);

            $prefix = $this->resolveApiPrefix();
            $this->logStep('api_prefix', ['prefix' => $prefix]);

            $response = $this->apiGet($prefix, '/inbounds/list');
            $inbounds = $response->json('obj') ?? $response->json() ?? [];

            $result = [
                'ok' => $response->successful(),
                'message' => __('services.sanaei_connect_ok'),
                'inbound_count' => is_array($inbounds) ? count($inbounds) : 0,
                'panel_url' => $this->url()->displayAddress(),
                'api_prefix' => $prefix,
                'debug' => $this->debugLog,
            ];

            $this->writeLog('info', 'Sanaei connection test succeeded', $result);

            return $result;
        } catch (\Throwable $exception) {
            $result = [
                'ok' => false,
                'message' => __('services.sanaei_connect_failed'),
                'error' => $exception->getMessage(),
                'panel_url' => $this->url()->displayAddress(),
                'tried_urls' => $tried,
                'debug' => $this->debugLog,
            ];

            $this->writeLog('warning', 'Sanaei connection test failed', $result);

            return $result;
        }
    }

    public function authenticate(): void
    {
        if ($this->server->api_token_enc && ! $this->sessionAuthForced && ! $this->hasPanelCredentials()) {
            $this->resolveReachableUrl();

            return;
        }

        if ($this->sessionCookie !== null && $this->hasPanelCredentials()) {
            return;
        }

        if ($this->server->username_enc === null || $this->server->password_enc === null) {
            throw new RemoteConnectionException(__('services.sanaei_credentials_missing', ['id' => $this->server->id]));
        }

        $this->resolveReachableUrl();
        $this->fetchCsrfToken();
        $this->performLogin();
    }

    public function resolveApiPrefix(): string
    {
        if (isset(self::$apiPrefixByServer[$this->server->id])) {
            return self::$apiPrefixByServer[$this->server->id];
        }

        $candidates = $this->apiPrefixCandidates();

        foreach ($candidates as $prefix) {
            try {
                $response = $this->apiGet($prefix, '/inbounds/list');
                $this->logStep('api_probe', [
                    'prefix' => $prefix,
                    'status' => $response->status(),
                    'success' => $response->successful(),
                ]);

                if ($response->successful() && $this->responseIsPanelApiJson($response)) {
                    self::$apiPrefixByServer[$this->server->id] = $prefix;

                    return $prefix;
                }
            } catch (\Throwable $exception) {
                $this->logStep('api_probe_failed', [
                    'prefix' => $prefix,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        throw new RemoteConnectionException(
            __('services.sanaei_api_not_found', ['list' => implode(', ', $candidates)])
            .($this->server->web_base_path ? '' : ' — '.__('services.sanaei_web_base_path_hint'))
        );
    }

    public function apiGet(string $prefix, string $path): Response
    {
        return $this->apiRequest('GET', $prefix, $path);
    }

    public function apiPost(string $prefix, string $path, array $payload = [], bool $asForm = false): Response
    {
        return $this->apiRequest('POST', $prefix, $path, $payload, $asForm);
    }

    public function apiRequest(string $method, string $prefix, string $path, array $payload = [], bool $asForm = false, ?int $timeoutSeconds = null): Response
    {
        if ($this->sessionAuthForced || ! $this->server->api_token_enc) {
            $this->authenticate();
        } else {
            $this->resolveReachableUrl();
        }

        $client = $this->http($timeoutSeconds);
        $url = $this->url()->api($prefix, $path);
        $client = $this->applyApiAuthHeaders($client);

        try {
            return match (strtoupper($method)) {
                'GET' => $client->get($url),
                'POST' => $asForm
                    ? $client->asForm()->post($url, $payload)
                    : $client->asJson()->post($url, $payload),
                'PUT' => $client->asJson()->put($url, $payload),
                'DELETE' => $client->delete($url, $payload),
                default => throw new RemoteConnectionException(__('services.http_method_unsupported', ['method' => $method])),
            };
        } catch (ConnectionException $exception) {
            throw new RemoteConnectionException($this->friendlyConnectionError($exception->getMessage()), 0, $exception);
        }
    }

    /**
     * POST addClient with alternate API prefixes, paths, and JSON/form bodies.
     *
     * @param  array<string, mixed>  $payload
     */
    public function postAddInboundClient(array $payload): Response
    {
        $this->ensureSessionForMutation();

        return $this->postPathAttempts(['/inbounds/addClient', '/inbound/addClient'], $payload);
    }

    /**
     * POST global client (3x-ui clients section) — attaches to inboundIds in payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function postCreateGlobalClient(array $payload): Response
    {
        $this->ensureSessionForMutation();

        return $this->postPathAttempts(['/clients/add'], $payload);
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $payload
     */
    public function postPathAttempts(array $paths, array $payload): Response
    {
        return $this->attemptPostRoutes($paths, $payload, true);
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $payload
     */
    protected function attemptPostRoutes(array $paths, array $payload, bool $allowSessionRetry): Response
    {
        $cacheKey = $this->server->id.'|'.implode(',', $paths);

        // Replay the route/encoding that already worked for this server instead
        // of walking the whole fallback matrix (one POST instead of ~20).
        if (isset(self::$resolvedPostRouteByServer[$cacheKey])) {
            [$knownUrl, $knownForm] = self::$resolvedPostRouteByServer[$cacheKey];
            $response = $this->postAbsolute($knownUrl, $payload, $knownForm);
            $this->logStep('api_post_attempt', [
                'url' => $knownUrl,
                'status' => $response->status(),
                'form' => $knownForm,
                'cached_route' => true,
            ]);

            if ($this->responseIsPanelApiJson($response)) {
                return $response;
            }

            unset(self::$resolvedPostRouteByServer[$cacheKey]);
        }

        $deadline = microtime(true) + max(5, (int) config('shahpanel.sanaei.operation_deadline_seconds', 45));
        $maxAttempts = max(1, (int) config('shahpanel.sanaei.max_post_attempts', 12));
        $attempts = 0;

        $urls = [];

        foreach ($paths as $path) {
            foreach ($this->apiUrlCandidatesFor($path) as $url) {
                $urls[] = $url;
            }
        }

        $last = null;
        $nonApi = null;

        foreach (array_values(array_unique($urls)) as $url) {
            foreach ([false, true] as $asForm) {
                if ($attempts >= $maxAttempts || microtime(true) >= $deadline) {
                    break 2;
                }

                $attempts++;
                $response = $this->postAbsolute($url, $payload, $asForm);
                $this->logStep('api_post_attempt', [
                    'url' => $url,
                    'status' => $response->status(),
                    'form' => $asForm,
                ]);

                if (in_array($response->status(), [404, 405], true)) {
                    $last = $response;

                    continue;
                }

                if ($this->responseIsPanelApiJson($response)) {
                    self::$resolvedPostRouteByServer[$cacheKey] = [$url, $asForm];

                    return $response;
                }

                // A 200 carrying HTML is 3x-ui serving its login page for an
                // expired session — never let that pass as a successful write.
                if ($response->successful()) {
                    $nonApi ??= $response;
                    $last = $response;

                    continue;
                }

                return $response;
            }
        }

        if ($nonApi !== null) {
            if ($allowSessionRetry) {
                $this->writeLog('warning', 'Sanaei answered a mutation with a non-API response; re-authenticating', [
                    'status' => $nonApi->status(),
                    'content_type' => (string) $nonApi->header('Content-Type'),
                    'attempts' => $attempts,
                ]);

                $this->refreshStaleSession();

                return $this->attemptPostRoutes($paths, $payload, false);
            }

            $this->writeLog('error', 'Sanaei mutation aborted: panel never returned an API response', [
                'status' => $nonApi->status(),
                'content_type' => (string) $nonApi->header('Content-Type'),
                'attempts' => $attempts,
            ]);

            throw new RemoteConnectionException(
                __('services.sanaei_html_instead_of_api')
            );
        }

        if ($last === null) {
            throw new RemoteConnectionException(__('services.sanaei_api_request_failed'));
        }

        if ($attempts >= $maxAttempts || microtime(true) >= $deadline) {
            $this->writeLog('warning', 'Sanaei mutation exhausted its attempt budget', [
                'attempts' => $attempts,
                'last_status' => $last->status(),
            ]);
        }

        return $last;
    }

    protected function refreshStaleSession(): void
    {
        unset(self::$apiPrefixByServer[$this->server->id]);
        $this->forceSessionAuthentication();
    }

    /**
     * @return list<string>
     */
    public function apiUrlCandidatesFor(string $apiPath): array
    {
        $apiPath = '/'.trim($apiPath, '/');
        $urls = [];

        foreach ($this->orderedApiPrefixes() as $prefix) {
            $urls[] = $this->url()->api($prefix, $apiPath);
        }

        $relative = ltrim($apiPath, '/');

        foreach ((array) config('shahpanel.sanaei.api_prefixes', ['/panel/api', '/xui/API', '/xui/api']) as $prefix) {
            $prefix = trim((string) $prefix, '/');
            if ($prefix !== '') {
                $urls[] = $this->url()->route('/'.$prefix.'/'.$relative);
            }
        }

        $urls[] = $this->url()->route('/panel/api/'.$relative);

        return array_values(array_unique($urls));
    }

    protected function postAbsolute(string $url, array $payload, bool $asForm): Response
    {
        $client = $this->applyApiAuthHeaders($this->http());

        try {
            return $asForm
                ? $client->asForm()->post($url, $payload)
                : $client->asJson()->post($url, $payload);
        } catch (ConnectionException $exception) {
            throw new RemoteConnectionException($this->friendlyConnectionError($exception->getMessage()), 0, $exception);
        }
    }

    protected function ensureSessionForMutation(): void
    {
        if (! $this->hasPanelCredentials()) {
            return;
        }

        // Reuse the established session. A fresh login per mutation means one
        // panel login per provisioned account, which trips 3x-ui's login-failure
        // ban. The session is refreshed only when the panel proves it is stale.
        if ($this->sessionCookie !== null) {
            return;
        }

        $this->forceSessionAuthentication();
    }

    protected function hasPanelCredentials(): bool
    {
        return $this->server->username_enc !== null && $this->server->password_enc !== null;
    }

    protected function responseIsPanelApiJson(Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $json = $response->json();

        return is_array($json) && (array_key_exists('success', $json) || array_key_exists('obj', $json));
    }

    /**
     * @return list<string>
     */
    public function orderedApiPrefixes(): array
    {
        try {
            $resolved = $this->resolveApiPrefix();
        } catch (\Throwable) {
            $resolved = null;
        }

        $candidates = $this->apiPrefixCandidates();

        if ($resolved !== null) {
            array_unshift($candidates, $resolved);
        }

        return array_values(array_unique($candidates));
    }

    protected function forceSessionAuthentication(): void
    {
        $this->sessionAuthForced = true;
        $this->sessionCookie = null;
        $this->csrfToken = null;
        $this->authenticate();
    }

    protected function applyApiAuthHeaders(\Illuminate\Http\Client\PendingRequest $client): \Illuminate\Http\Client\PendingRequest
    {
        if ($this->server->api_token_enc && ! $this->sessionAuthForced && ! $this->hasPanelCredentials()) {
            $client = $client->withToken($this->server->api_token_enc);
        } elseif ($this->sessionCookie !== null) {
            $client = $client->withHeaders(['Cookie' => $this->sessionCookie]);
        }

        $headers = [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ];

        if ($this->csrfToken !== null && ($this->sessionAuthForced || ! $this->server->api_token_enc || $this->hasPanelCredentials())) {
            $headers['X-CSRF-Token'] = $this->csrfToken;
        }

        return $client->withHeaders($headers);
    }

    /**
     * @return list<string>
     */
    public function recentPostAttemptUrls(int $limit = 5): array
    {
        $urls = [];

        foreach (array_reverse($this->debugLog()) as $entry) {
            if (($entry['step'] ?? '') !== 'api_post_attempt' || empty($entry['url'])) {
                continue;
            }

            $urls[] = (string) $entry['url'];

            if (count($urls) >= $limit) {
                break;
            }
        }

        return array_reverse($urls);
    }

    public function postWithFallback(string $legacyPath, string $modernPath, array $legacyPayload, array $modernPayload): Response
    {
        $response = $this->postPathAttempts([$modernPath], $modernPayload);

        if (! in_array($response->status(), [404, 405], true)) {
            return $response;
        }

        return $this->postPathAttempts([$legacyPath], $legacyPayload);
    }

    /**
     * @param  list<string>  $tried
     */
    protected function resolveReachableUrl(array &$tried = []): SanaeiPanelUrl
    {
        if ($this->activeUrl !== null) {
            return $this->activeUrl;
        }

        if (isset(self::$resolvedUrlByServer[$this->server->id])) {
            $this->activeUrl = self::$resolvedUrlByServer[$this->server->id];

            return $this->activeUrl;
        }

        $lastError = null;

        foreach (SanaeiPanelUrl::candidatesFromServer($this->server) as $candidate) {
            $address = $candidate->displayAddress();
            $tried[] = $address;

            $toTry = [$candidate];

            if (str_starts_with(strtolower($candidate->origin), 'http://')) {
                $upgrade = SanaeiHttpProbe::detectHttpsUpgrade($candidate->origin, $candidate->basePath);
                $this->logStep('http_upgrade_check', array_merge(['origin' => $candidate->origin], $upgrade));

                if (! empty($upgrade['upgrade_to'])) {
                    $upgraded = SanaeiPanelUrl::fromLocationHeader($upgrade['upgrade_to'], $candidate->basePath);
                    if ($upgraded !== null) {
                        $toTry[] = $upgraded;
                    }
                }
            }

            foreach ($toTry as $probeCandidate) {
                $probeAddress = $probeCandidate->displayAddress();
                if (! in_array($probeAddress, $tried, true)) {
                    $tried[] = $probeAddress;
                }

                $probeResult = $this->probe($probeCandidate);
                $this->logStep('probe', array_merge(['url' => $probeAddress], $probeResult));

                if ($probeResult['reachable'] ?? false) {
                    $this->activeUrl = $probeCandidate;
                    self::$resolvedUrlByServer[$this->server->id] = $probeCandidate;

                    return $probeCandidate;
                }

                $lastError = $probeResult['error'] ?? $lastError;
            }
        }

        throw new RemoteConnectionException(
            $this->friendlyConnectionError($lastError ?? 'no reachable URL', $tried)
        );
    }

    /**
     * @return array{reachable: bool, error?: string, matched?: string, status?: int, body_snippet?: string}
     */
    protected function probe(SanaeiPanelUrl $url): array
    {
        $getPaths = [
            '/' => 'root',
            '/panel/' => 'panel',
        ];

        $lastStatus = null;
        $lastBody = null;

        foreach ($getPaths as $path => $label) {
            $probeUrl = $url->route($path);

            try {
                $response = $this->http()->withHeaders([
                    'Accept' => 'text/html,application/json,*/*',
                    'X-Requested-With' => 'XMLHttpRequest',
                ])->get($probeUrl);

                if ($this->isValidPanelResponse($response)) {
                    return [
                        'reachable' => true,
                        'matched' => $label,
                        'status' => $response->status(),
                        'body_snippet' => substr((string) $response->body(), 0, 200),
                    ];
                }

                $lastStatus = $response->status();
                $lastBody = substr((string) $response->body(), 0, 200);
            } catch (ConnectionException $exception) {
                $transportError = $this->probeTransportError($url, $probeUrl, $exception);
                if ($transportError !== null) {
                    return $transportError;
                }

                return ['reachable' => false, 'error' => $exception->getMessage()];
            } catch (\Throwable $exception) {
                return ['reachable' => false, 'error' => $exception->getMessage()];
            }
        }

        $loginProbe = $this->probeLoginPost($url);
        if ($loginProbe !== null) {
            return $loginProbe;
        }

        return [
            'reachable' => false,
            'error' => __('services.sanaei_panel_path_undetected', ['status' => $lastStatus ?? '?']),
            'status' => $lastStatus,
            'body_snippet' => $lastBody,
        ];
    }

    /**
     * 3x-ui v2.9+ exposes login only via POST (GET /login returns 404).
     *
     * @return array{reachable: bool, matched: string, status: int, body_snippet?: string}|null
     */
    protected function probeLoginPost(SanaeiPanelUrl $url): ?array
    {
        $loginUrl = $url->route('/login');

        try {
            $response = $this->http()->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])->asForm()->post($loginUrl, [
                'username' => '__probe__',
                'password' => '__probe__',
            ]);

            if ($this->isLoginApiResponse($response)) {
                return [
                    'reachable' => true,
                    'matched' => 'login_post',
                    'status' => $response->status(),
                    'body_snippet' => substr((string) $response->body(), 0, 200),
                ];
            }
        } catch (ConnectionException $exception) {
            $this->probeTransportError($url, $loginUrl, $exception);
        } catch (\Throwable $exception) {
            $this->logStep('login_probe_failed', ['reason' => $exception->getMessage()]);
        }

        return null;
    }

    /**
     * @return array{reachable: false, error: string}|null
     */
    protected function probeTransportError(SanaeiPanelUrl $url, string $probeUrl, ConnectionException $exception): ?array
    {
        $message = $exception->getMessage();

        if (! str_contains($message, 'Unsupported HTTP version')) {
            return null;
        }

        $upgrade = SanaeiHttpProbe::detectHttpsUpgrade($url->origin, $url->basePath);
        $this->logStep('http0_redirect', array_merge(['url' => $probeUrl], $upgrade));

        return [
            'reachable' => false,
            'error' => __('services.sanaei_https_redirect')
                .(! empty($upgrade['upgrade_to']) ? ' '.__('services.target_url', ['url' => $upgrade['upgrade_to']]) : ''),
        ];
    }

    protected function isLoginApiResponse(Response $response): bool
    {
        $json = $response->json();

        return is_array($json) && array_key_exists('success', $json);
    }

    protected function isValidPanelResponse(Response $response): bool
    {
        $status = $response->status();

        if ($status >= 500) {
            return false;
        }

        if ($status === 404) {
            return false;
        }

        $body = (string) $response->body();
        $lower = strtolower($body);

        if ($response->json() !== null) {
            return true;
        }

        if (str_contains($lower, '3x-ui') || str_contains($lower, 'login-app')
            || str_contains($lower, 'ant-design-vue') || str_contains($lower, 'axios.defaults.baseurl')
            || str_contains($lower, 'login') || str_contains($lower, 'password')) {
            return true;
        }

        return in_array($status, [200, 401, 403, 405], true);
    }

    /**
     * @return list<string>
     */
    protected function apiPrefixCandidates(): array
    {
        $configured = trim((string) config('shahpanel.sanaei.api_prefix', '/panel/api'), '/');
        $fromUrl = trim($this->url()->apiPrefixOverride ?? '', '/');
        $defaults = config('shahpanel.sanaei.api_prefixes', ['/panel/api', '/xui/API', '/xui/api']);

        $candidates = [];
        if ($fromUrl !== '') {
            $candidates[] = '/'.$fromUrl;
        }
        if ($configured !== '') {
            $candidates[] = '/'.$configured;
        }
        foreach ((array) $defaults as $prefix) {
            $prefix = trim((string) $prefix, '/');
            if ($prefix !== '') {
                $candidates[] = '/'.$prefix;
            }
        }

        return array_values(array_unique($candidates));
    }

    protected function fetchCsrfToken(): void
    {
        if ($this->server->api_token_enc && ! $this->sessionAuthForced) {
            return;
        }

        $csrfUrl = $this->url()->route('/csrf-token');

        try {
            $response = $this->http()->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])->get($csrfUrl);

            $this->logStep('csrf', [
                'url' => $csrfUrl,
                'status' => $response->status(),
            ]);

            if (! $response->successful()) {
                return;
            }

            $token = $response->json('obj') ?? $response->json('token') ?? $response->json('csrfToken');
            if (is_string($token) && $token !== '') {
                $this->csrfToken = $token;
            }

            $this->captureSessionCookie($response->headers());
        } catch (\Throwable $exception) {
            $this->logStep('csrf_skipped', ['reason' => $exception->getMessage()]);
        }
    }

    protected function performLogin(): void
    {
        $headers = ['X-Requested-With' => 'XMLHttpRequest'];
        if ($this->csrfToken !== null) {
            $headers['X-CSRF-Token'] = $this->csrfToken;
        }

        $credentials = [
            'username' => $this->server->username_enc,
            'password' => $this->server->password_enc,
        ];

        $client = $this->http()->withHeaders($headers);
        $loginUrl = $this->url()->route('/login');

        $response = $client->asForm()->post($loginUrl, $credentials);

        $this->logStep('login_form', [
            'url' => $loginUrl,
            'status' => $response->status(),
            'body_snippet' => substr((string) $response->body(), 0, 300),
        ]);

        if (! $response->successful()) {
            $response = $client->asJson()->post($loginUrl, $credentials);
            $this->logStep('login_json', [
                'url' => $loginUrl,
                'status' => $response->status(),
                'body_snippet' => substr((string) $response->body(), 0, 300),
            ]);
        }

        if (! $response->successful()) {
            throw new RemoteConnectionException(
                __('services.sanaei_login_failed_http', ['status' => $response->status(), 'url' => $loginUrl])
                .($response->status() === 404 ? ' — '.__('services.sanaei_login_path_missing') : '')
            );
        }

        $json = $response->json();
        if (is_array($json) && array_key_exists('success', $json) && $json['success'] === false) {
            throw new RemoteConnectionException(__('services.sanaei_login_failed', ['reason' => panel_api_message($json['msg'] ?? $json['message'] ?? null, __('services.sanaei_bad_credentials'))]));
        }

        $cookie = $this->captureSessionCookie($response->headers()) ?? $this->captureSessionCookieFromJar();
        if ($cookie === null) {
            throw new RemoteConnectionException(__('services.sanaei_login_no_session_cookie'));
        }
    }

    protected function captureSessionCookieFromJar(): ?string
    {
        foreach ($this->cookieJar->toArray() as $cookie) {
            $name = $cookie['Name'] ?? '';
            if (in_array($name, ['3x-ui', 'session'], true)) {
                $this->sessionCookie = $name.'='.($cookie['Value'] ?? '');

                return $this->sessionCookie;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     */
    protected function captureSessionCookie(array $headers): ?string
    {
        $cookies = $headers['Set-Cookie'] ?? $headers['set-cookie'] ?? [];

        foreach ((array) $cookies as $cookie) {
            if (preg_match('/^(3x-ui=[^;]+)/', $cookie, $matches)) {
                $this->sessionCookie = $matches[1];

                return $this->sessionCookie;
            }
            if (preg_match('/^(session=[^;]+)/', $cookie, $matches)) {
                $this->sessionCookie = $matches[1];

                return $this->sessionCookie;
            }
        }

        return $this->sessionCookie;
    }

    protected function http(?int $timeoutSeconds = null)
    {
        $timeout = max(1, $timeoutSeconds ?? $this->timeoutSeconds());

        // A dead host must not burn the whole request budget on the handshake.
        $connectTimeout = max(1, min($timeout, (int) config('shahpanel.sanaei.connect_timeout_seconds', 5)));

        // The panel admin username/password are POSTed on every login, so the
        // certificate is verified by default. Opt out per server, or globally
        // with SANAEI_VERIFY_SSL=false, for a self-signed panel certificate.
        $verify = (bool) ($this->server->sanaei_verify_ssl ?? config('shahpanel.sanaei.verify_ssl', true));

        return Http::timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->acceptJson()
            ->withOptions([
                'verify' => $verify,
                'cookies' => $this->cookieJar,
                'version' => 1.1,
            ]);
    }

    protected function timeoutSeconds(): int
    {
        return (int) config('shahpanel.sync_api_timeout_seconds', 15);
    }

    /**
     * @param  list<string>  $tried
     */
    protected function friendlyConnectionError(string $message, array $tried = []): string
    {
        if (str_contains($message, 'certificate') || str_contains($message, 'SSL')
            || str_contains($message, 'cURL error 60') || str_contains($message, 'cURL error 51')) {
            $hint = 'گواهی SSL پنل تأیید نشد. اگر پنل گواهی self-signed دارد، «تأیید گواهی SSL» را برای این سرور خاموش کنید'
                .' یا SANAEI_VERIFY_SSL=false را در فایل .env قرار دهید.';
        } elseif (str_contains($message, 'Unsupported HTTP version')) {
            $hint = 'پنل با HTTP/0.0 به HTTPS ریدایرکت می‌کند — آدرس را https://domain:2053/مسیر وارد کنید یا پورت 2053 را با HTTPS امتحان کنید.';
        } elseif (str_contains($message, 'Failed to connect') || str_contains($message, 'Couldn\'t connect')) {
            $hint = 'اتصال TCP برقرار نشد. اگر هاست سامانه پورت خروجی را بسته، IP سرور را در whitelist پنل Sanaei قرار دهید.';
        } elseif (str_contains($message, 'webBasePath') || str_contains($message, '404')) {
            $hint = 'اتصال TCP برقرار شد ولی مسیر پنل یافت نشد — webBasePath را در فیلد «مسیر پایه وب» وارد کنید.';
        } else {
            $hint = 'خطای اتصال Sanaei';
        }

        $detail = $message;

        if ($tried !== []) {
            $detail .= ' — امتحان‌شده: '.implode(' | ', array_slice($tried, 0, 8));
        }

        return $hint.': '.$detail;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logStep(string $step, array $context = []): void
    {
        $entry = array_merge(['step' => $step, 'at' => now()->toIso8601String()], $context);
        $this->debugLog[] = $entry;
    }

    /**
     * Keep scheme/host/port (useful for diagnosis) but drop the path, which on
     * 3x-ui is the secret webBasePath.
     */
    protected static function redactUrl(string $url): string
    {
        $parts = @parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return '[redacted]';
        }

        $origin = ($parts['scheme'] ?? 'https').'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '');

        return ($parts['path'] ?? '/') !== '/' ? $origin.'/[redacted-path]' : $origin;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected static function redactPanelUrls(array $context): array
    {
        foreach (['panel_url', 'url'] as $key) {
            if (isset($context[$key]) && is_string($context[$key])) {
                $context[$key] = self::redactUrl($context[$key]);
            }
        }

        if (isset($context['tried_urls']) && is_array($context['tried_urls'])) {
            $context['tried_urls'] = array_map(
                static fn ($url) => is_string($url) ? self::redactUrl($url) : '[redacted]',
                $context['tried_urls']
            );
        }

        if (isset($context['debug']) && is_array($context['debug'])) {
            $context['debug'] = array_map(static function ($entry) {
                if (is_array($entry) && isset($entry['url']) && is_string($entry['url'])) {
                    $entry['url'] = self::redactUrl($entry['url']);
                }

                return $entry;
            }, $context['debug']);
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function writeLog(string $level, string $message, array $context = []): void
    {
        // web_base_path is 3x-ui's only obscurity control for an internet-facing
        // admin panel — never write it (or full panel URLs) to the log.
        Log::channel('sanaei')->{$level}($message, array_merge([
            'server_id' => $this->server->id,
            'host' => $this->server->host,
            'port' => $this->server->port,
            'has_web_base_path' => filled($this->server->web_base_path),
        ], self::redactPanelUrls($context)));
    }
}
