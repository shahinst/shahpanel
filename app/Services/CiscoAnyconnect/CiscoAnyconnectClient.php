<?php

namespace App\Services\CiscoAnyconnect;

use App\Exceptions\RemoteConnectionException;
use App\Exceptions\RemoteProvisionException;
use App\Models\Server;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cisco ASA / Secure Firewall REST client for AnyConnect (Secure Client) RA users.
 *
 * - Auth: Basic, or X-Auth-Token via POST /api/tokenservices
 * - User-Agent: "REST API Agent" (required by ASA basic-auth-client list)
 * - Provisioning: POST /api/cli (local username + VPN attributes)
 *
 * @see /docs/CISCO_ANYCONNECT_API.md
 */
final class CiscoAnyconnectClient
{
    protected ?string $authToken = null;

    protected bool $authProbed = false;

    public function __construct(protected Server $server) {}

    public function baseUrl(): string
    {
        $host = $this->server->apiConnectionHost();
        $port = (int) ($this->server->port ?: 443);

        return sprintf('https://%s:%d/api', $host, $port);
    }

    /**
     * @return array{ok: bool, message: string, error?: string, asa_version?: string, user_count?: int, api_url?: string}
     */
    public function testConnection(): array
    {
        try {
            $this->authenticate();
            $users = $this->listLocalUsers();
            $version = $this->fetchSoftwareVersion();

            return [
                'ok' => true,
                'message' => __('services.cisco_connect_ok'),
                'api_url' => $this->baseUrl(),
                'asa_version' => $version ?? 'unknown',
                'user_count' => count($users),
            ];
        } catch (Throwable $exception) {
            Log::channel('cisco_anyconnect')->warning('ASA connection test failed', [
                'server_id' => $this->server->id,
                'host' => $this->server->host,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => __('services.cisco_asa_connect_failed'),
                'error' => $exception->getMessage(),
                'api_url' => $this->baseUrl(),
            ];
        }
    }

    public function authenticate(): void
    {
        // ASA caps concurrent REST tokens (5 per user by default). Authenticate
        // once per client instance instead of once per CLI call.
        if ($this->authToken !== null || $this->authProbed) {
            return;
        }

        $username = trim((string) ($this->server->username_enc ?? ''));
        $password = (string) ($this->server->password_enc ?? '');

        if ($username === '' || $password === '') {
            throw new RemoteConnectionException(
                __('services.cisco_admin_credentials_required')
            );
        }

        try {
            $response = $this->rawHttp()
                ->withBasicAuth($username, $password)
                ->post($this->baseUrl().'/tokenservices', (object) []);

            if ($response->status() === 204 || $response->successful()) {
                $token = $response->header('X-Auth-Token') ?: $response->header('x-auth-token');
                if (is_string($token) && $token !== '') {
                    $this->authToken = $token;
                    $this->authProbed = true;

                    return;
                }
            }
        } catch (Throwable $exception) {
            Log::channel('cisco_anyconnect')->warning('Token auth unavailable, falling back to basic auth', [
                'server_id' => $this->server->id,
                'error' => $exception->getMessage(),
            ]);
        }

        // Probe with basic auth — 404 on localusers is acceptable (image variance).
        $probe = $this->request('get', '/objects/localusers');
        if (! $probe->successful() && ! in_array($probe->status(), [404, 405], true)) {
            throw new RemoteConnectionException(
                __('services.cisco_auth_failed', ['status' => $probe->status(), 'detail' => $this->snippet($probe)])
            );
        }

        $this->authProbed = true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLocalUsers(): array
    {
        $response = $this->request('get', '/objects/localusers');

        if (in_array($response->status(), [404, 405], true)) {
            return [];
        }

        if (! $response->successful()) {
            throw new RemoteConnectionException(
                __('services.cisco_read_local_users_failed', ['status' => $response->status(), 'detail' => $this->snippet($response)])
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            return [];
        }

        $items = $json['items'] ?? $json['response'] ?? $json;

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /**
     * Create/update AnyConnect RA local user via ASA CLI API.
     *
     * @param  array{
     *     group_policy?: ?string,
     *     tunnel_group?: ?string,
     *     simultaneous_logins?: int,
     *     write_memory?: bool
     * }  $options
     */
    public function provisionVpnUser(string $username, string $password, array $options = []): void
    {
        $groupPolicy = trim((string) ($options['group_policy'] ?? ''));
        $tunnelGroup = trim((string) ($options['tunnel_group'] ?? ''));
        $logins = max(0, (int) ($options['simultaneous_logins'] ?? 1));
        $writeMemory = (bool) ($options['write_memory'] ?? true);

        $commands = [
            'username '.self::cliQuote($username).' password '.self::cliQuote($password).' privilege 0',
            'username '.self::cliQuote($username).' attributes',
            'service-type remote-access',
            'vpn-tunnel-protocol ssl-client',
            'vpn-simultaneous-logins '.$logins,
        ];

        if ($groupPolicy !== '') {
            $commands[] = 'vpn-group-policy '.self::cliQuote($groupPolicy);
        }

        if ($tunnelGroup !== '') {
            $commands[] = 'group-lock value '.self::cliQuote($tunnelGroup);
        }

        $commands[] = 'exit';

        $this->runCli($commands);

        if ($writeMemory) {
            $this->writeMemory();
        }
    }

    public function setVpnUserEnabled(
        string $username,
        bool $enabled,
        int $simultaneousLogins = 1,
        bool $writeMemory = true,
    ): void {
        $logins = $enabled ? max(1, $simultaneousLogins) : 0;

        $commands = [
            'username '.self::cliQuote($username).' attributes',
            'vpn-simultaneous-logins '.$logins,
            'exit',
        ];

        $this->runCli($commands);

        if ($writeMemory) {
            $this->writeMemory();
        }
    }

    public function removeVpnUser(string $username, bool $writeMemory = true): void
    {
        $commands = [
            'clear configure username '.self::cliQuote($username),
        ];

        try {
            $this->runCli($commands);
        } catch (Throwable $exception) {
            Log::channel('cisco_anyconnect')->warning('ASA CLI user removal failed, trying REST fallback', [
                'server_id' => $this->server->id,
                'username' => $username,
                'error' => $exception->getMessage(),
            ]);

            $response = $this->request('delete', '/objects/localusers/'.rawurlencode($username));
            if (! $response->successful() && $response->status() !== 404) {
                throw new RemoteProvisionException(
                    __('services.cisco_delete_user_failed', ['error' => $exception->getMessage(), 'status' => $response->status()])
                );
            }
        }

        if ($writeMemory) {
            $this->writeMemory();
        }
    }

    /**
     * @param  list<string>  $commands
     */
    public function runCli(array $commands, ?int $timeoutSeconds = null): string
    {
        $this->authenticate();

        $response = $this->request('post', '/cli', [
            'commands' => array_values($commands),
        ], $timeoutSeconds);

        if (! $response->successful()) {
            throw new RemoteProvisionException(
                __('services.cisco_cli_failed_http', ['status' => $response->status(), 'detail' => $this->snippet($response)])
            );
        }

        $output = $this->cliOutput($response);

        // ASA answers a rejected command with HTTP 200 and the error in the body.
        if (preg_match('/^\s*(?:ERROR\b|%)[^\r\n]*/mi', $output, $matches) === 1) {
            throw new RemoteProvisionException(__('services.cisco_cli_failed', ['detail' => trim($matches[0])]));
        }

        return $output;
    }

    /**
     * `write memory` can outlast a normal API call on a busy ASA, so it gets its
     * own (longer) budget instead of sharing the provisioning request timeout.
     */
    public function writeMemory(): void
    {
        $this->runCli(
            ['write memory'],
            max(60, (int) config('shahpanel.cisco_anyconnect.write_memory_timeout_seconds', 180))
        );
    }

    protected function cliOutput(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            $body = $json['response'] ?? null;
            if (is_array($body)) {
                return implode("\n", array_map(
                    static fn ($line) => is_scalar($line) ? (string) $line : (string) json_encode($line),
                    $body
                ));
            }
            if (is_string($body)) {
                return $body;
            }
        }

        return (string) $response->body();
    }

    protected function fetchSoftwareVersion(): ?string
    {
        try {
            $out = $this->runCli(['show version | include Cisco Adaptive Security']);
            $line = trim(explode("\n", $out)[0] ?? '');

            return $line !== '' ? $line : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    protected function request(string $method, string $path, ?array $json = null, ?int $timeoutSeconds = null): Response
    {
        $url = $this->baseUrl().'/'.ltrim($path, '/');
        $http = $this->authedHttp($timeoutSeconds);

        return match (strtolower($method)) {
            'get' => $http->get($url),
            'post' => $http->post($url, $json ?? (object) []),
            'put' => $http->put($url, $json ?? (object) []),
            'delete' => $http->delete($url),
            default => throw new RemoteConnectionException('Unsupported HTTP method: '.$method),
        };
    }

    protected function authedHttp(?int $timeoutSeconds = null): PendingRequest
    {
        $http = $this->rawHttp($timeoutSeconds);

        if ($this->authToken) {
            return $http->withHeaders(['X-Auth-Token' => $this->authToken]);
        }

        return $http->withBasicAuth(
            trim((string) ($this->server->username_enc ?? '')),
            (string) ($this->server->password_enc ?? ''),
        );
    }

    protected function rawHttp(?int $timeoutSeconds = null): PendingRequest
    {
        $timeout = max(15, $timeoutSeconds ?? (int) config('shahpanel.cisco_anyconnect.timeout_seconds', 45));
        $verify = (bool) ($this->server->cisco_verify_ssl ?? config('shahpanel.cisco_anyconnect.verify_ssl', true));

        return Http::timeout($timeout)
            ->connectTimeout(min(20, $timeout))
            ->withHeaders([
                'User-Agent' => 'REST API Agent',
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->withOptions(['verify' => $verify])
            ->acceptJson();
    }

    protected function snippet(Response $response): string
    {
        return mb_substr(trim((string) $response->body()), 0, 300);
    }

    public static function cliQuote(string $value): string
    {
        // ASA splits CLI lines on CR/LF before honouring quotes, so a control
        // character here would run an extra command at privilege 15.
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new RemoteProvisionException(__('services.cisco_invalid_command_value'));
        }

        if ($value === '' || preg_match('/[\s"\\\\]/', $value) === 1) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }
}
