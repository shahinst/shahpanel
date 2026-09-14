<?php

namespace App\Services\Ocserv;

use App\Exceptions\RemoteConnectionException;
use App\Exceptions\RemoteProvisionException;
use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ocserv / OpenConnect management API client.
 *
 * The panel never touches the ocserv binary, occtl or SSH — it talks to the
 * HTTP JSON management service that runs next to ocserv on the VPN server.
 *
 * - Base URL: https://{host}:{api_port}/api (default port 9443)
 * - Auth: HTTP Basic, where the password is the API token
 * - Changes apply immediately; there is no `write memory` equivalent
 *
 * @see /docs/OCSERV_API.md
 */
final class OcservClient
{
    public function __construct(protected Server $server) {}

    public function baseUrl(): string
    {
        $host = $this->server->apiConnectionHost();
        $port = $this->apiPort();

        return sprintf('https://%s:%d/api', $host, $port);
    }

    public function apiPort(): int
    {
        $port = (int) ($this->server->ocserv_api_port ?? 0);

        if ($port <= 0) {
            $port = (int) ($this->server->port ?? 0);
        }

        if ($port <= 0) {
            $port = (int) config('vpnpanel.ocserv.default_port', 9443);
        }

        return $port > 0 ? $port : 9443;
    }

    /**
     * @return array{ok: bool, message: string, error?: string, user_count?: int, api_url?: string}
     */
    public function testConnection(): array
    {
        try {
            $health = $this->decode($this->request('get', '/health'));

            if (($health['ok'] ?? null) !== true) {
                throw new RemoteConnectionException(
                    'سرویس مدیریتی ocserv وضعیت سالم گزارش نکرد.'
                );
            }

            $users = $this->listUsers();

            Log::channel('ocserv')->info('ocserv connection test succeeded', [
                'server_id' => $this->server->id,
                'api_url' => $this->baseUrl(),
                'user_count' => count($users),
            ]);

            return [
                'ok' => true,
                'message' => 'اتصال به سرویس مدیریتی ocserv برقرار شد.',
                'api_url' => $this->baseUrl(),
                'user_count' => count($users),
            ];
        } catch (RemoteConnectionException|RemoteProvisionException $exception) {
            Log::channel('ocserv')->warning('ocserv connection test failed', [
                'server_id' => $this->server->id,
                'host' => $this->server->host,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => 'اتصال به سرویس مدیریتی ocserv ناموفق بود.',
                'error' => $exception->getMessage(),
                'api_url' => $this->baseUrl(),
            ];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUsers(): array
    {
        $json = $this->decode($this->request('get', '/users'));
        $users = $json['users'] ?? [];

        return is_array($users) ? array_values(array_filter($users, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>|null  null when the user does not exist on the server
     */
    public function getUser(string $username): ?array
    {
        $response = $this->request('get', '/users/'.rawurlencode($username));

        if ($response->status() === 404) {
            return null;
        }

        return $this->decode($response);
    }

    public function userExists(string $username): bool
    {
        return $this->getUser($username) !== null;
    }

    /**
     * POST /api/users — 409 means the username is already taken on this server.
     */
    public function createUser(
        string $username,
        string $password,
        ?string $group = null,
        int $maxSessions = 1,
    ): void {
        $response = $this->request('post', '/users', [
            'username' => $username,
            'password' => $password,
            'max_sessions' => max(0, $maxSessions),
            'group' => $group !== null && $group !== '' ? $group : null,
        ]);

        if ($response->status() === 409) {
            Log::channel('ocserv')->warning('ocserv user already exists', [
                'server_id' => $this->server->id,
                'username' => $username,
            ]);

            throw new RemoteProvisionException(
                'کاربر «'.$username.'» از قبل روی سرور ocserv وجود دارد.'
            );
        }

        $this->assertSuccessful($response, 'ساخت کاربر ocserv');

        Log::channel('ocserv')->info('ocserv user created', [
            'server_id' => $this->server->id,
            'username' => $username,
            'group' => $group,
            'max_sessions' => max(0, $maxSessions),
        ]);
    }

    /**
     * Create the user, or bring an existing one back in line with the package.
     */
    public function upsertUser(
        string $username,
        string $password,
        ?string $group = null,
        int $maxSessions = 1,
    ): void {
        if (! $this->userExists($username)) {
            $this->createUser($username, $password, $group, $maxSessions);

            return;
        }

        $this->setPassword($username, $password);
        $this->setMaxSessions($username, $maxSessions);
    }

    public function setPassword(string $username, string $password): void
    {
        $response = $this->request('put', '/users/'.rawurlencode($username).'/password', [
            'password' => $password,
        ]);

        $this->assertSuccessful($response, 'تغییر رمز کاربر ocserv');

        Log::channel('ocserv')->info('ocserv user password updated', [
            'server_id' => $this->server->id,
            'username' => $username,
        ]);
    }

    public function setMaxSessions(string $username, int $maxSessions): void
    {
        $response = $this->request('put', '/users/'.rawurlencode($username).'/limits', [
            'max_sessions' => max(0, $maxSessions),
        ]);

        $this->assertSuccessful($response, 'تنظیم محدودیت نشست کاربر ocserv');

        Log::channel('ocserv')->info('ocserv user limits updated', [
            'server_id' => $this->server->id,
            'username' => $username,
            'max_sessions' => max(0, $maxSessions),
        ]);
    }

    public function lockUser(string $username): void
    {
        $response = $this->request('post', '/users/'.rawurlencode($username).'/lock');

        $this->assertSuccessful($response, 'قفل کردن کاربر ocserv');

        Log::channel('ocserv')->info('ocserv user locked', [
            'server_id' => $this->server->id,
            'username' => $username,
        ]);
    }

    public function unlockUser(string $username): void
    {
        $response = $this->request('post', '/users/'.rawurlencode($username).'/unlock');

        $this->assertSuccessful($response, 'باز کردن کاربر ocserv');

        Log::channel('ocserv')->info('ocserv user unlocked', [
            'server_id' => $this->server->id,
            'username' => $username,
        ]);
    }

    /**
     * DELETE /api/users/{u} — 404 means the user is already gone (idempotent success).
     */
    public function deleteUser(string $username): void
    {
        $response = $this->request('delete', '/users/'.rawurlencode($username));

        if ($response->status() === 404) {
            Log::channel('ocserv')->info('ocserv user already absent on delete', [
                'server_id' => $this->server->id,
                'username' => $username,
            ]);

            return;
        }

        $this->assertSuccessful($response, 'حذف کاربر ocserv');

        Log::channel('ocserv')->info('ocserv user deleted', [
            'server_id' => $this->server->id,
            'username' => $username,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSessions(): array
    {
        $json = $this->decode($this->request('get', '/sessions'));
        $sessions = $json['sessions'] ?? [];

        return is_array($sessions) ? array_values(array_filter($sessions, 'is_array')) : [];
    }

    /**
     * POST /api/sessions/{u}/disconnect — 404 simply means no live session.
     */
    public function disconnectUser(string $username): void
    {
        $response = $this->request('post', '/sessions/'.rawurlencode($username).'/disconnect');

        if ($response->status() === 404) {
            return;
        }

        $this->assertSuccessful($response, 'قطع نشست کاربر ocserv');

        Log::channel('ocserv')->info('ocserv user session disconnected', [
            'server_id' => $this->server->id,
            'username' => $username,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function traffic(): array
    {
        return $this->decode($this->request('get', '/traffic'));
    }

    /**
     * @return array<string, mixed>
     */
    public function tunnels(): array
    {
        return $this->decode($this->request('get', '/tunnels'));
    }

    /**
     * A connection-level failure is retried exactly once; an HTTP response of any
     * status (4xx included) is returned to the caller and never retried.
     *
     * @param  array<string, mixed>|null  $json
     */
    protected function request(string $method, string $path, ?array $json = null): Response
    {
        $url = $this->baseUrl().'/'.ltrim($path, '/');
        $verb = strtolower($method);
        $lastError = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                return $this->send($verb, $url, $json);
            } catch (ConnectionException $exception) {
                $lastError = $exception;

                Log::channel('ocserv')->warning('ocserv API connection attempt failed', [
                    'server_id' => $this->server->id,
                    'method' => $verb,
                    'url' => $url,
                    'attempt' => $attempt,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        throw new RemoteConnectionException(
            'ارتباط با سرویس مدیریتی ocserv برقرار نشد: '.mb_substr((string) $lastError?->getMessage(), 0, 300),
            previous: $lastError
        );
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    protected function send(string $verb, string $url, ?array $json): Response
    {
        $http = $this->authedHttp();

        return match ($verb) {
            'get' => $http->get($url),
            'post' => $http->post($url, $json ?? (object) []),
            'put' => $http->put($url, $json ?? (object) []),
            'delete' => $http->delete($url),
            default => throw new RemoteConnectionException('Unsupported HTTP method: '.$verb),
        };
    }

    protected function authedHttp(): PendingRequest
    {
        $username = trim((string) ($this->server->username_enc ?? ''));
        $password = (string) ($this->server->password_enc ?? '');

        if ($username === '' || $password === '') {
            throw new RemoteConnectionException(
                'نام کاربری و توکن API سرویس مدیریتی ocserv الزامی است.'
            );
        }

        return $this->rawHttp()->withBasicAuth($username, $password);
    }

    protected function rawHttp(): PendingRequest
    {
        $timeout = max(5, (int) config('vpnpanel.ocserv.timeout_seconds', 30));
        $connectTimeout = max(2, (int) config('vpnpanel.ocserv.connect_timeout_seconds', 10));
        $verify = (bool) ($this->server->ocserv_verify_ssl ?? config('vpnpanel.ocserv.verify_ssl', true));

        return Http::timeout($timeout)
            ->connectTimeout(min($connectTimeout, $timeout))
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->withOptions(['verify' => $verify])
            ->acceptJson();
    }

    protected function assertSuccessful(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RemoteProvisionException(
            $operation.' ناموفق بود (HTTP '.$response->status().'): '.$this->snippet($response)
        );
    }

    /**
     * A 200 that is not JSON (a proxy or Cloudflare interstitial) is a failure,
     * never an empty success.
     *
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        if (! $response->successful()) {
            throw new RemoteConnectionException(
                'درخواست به سرویس مدیریتی ocserv ناموفق بود (HTTP '.$response->status().'): '.$this->snippet($response)
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RemoteConnectionException(
                'پاسخ سرویس مدیریتی ocserv JSON معتبر نبود: '.$this->snippet($response)
            );
        }

        return $json;
    }

    protected function snippet(Response $response): string
    {
        return mb_substr(trim((string) $response->body()), 0, 300);
    }
}
