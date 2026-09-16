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
use Throwable;

/**
 * HTTP client for ocserv (OpenConnect) panel API.
 *
 * Auth: HTTP Basic (panel username + API token as password).
 * Base: https://{host}:{port}
 */
final class OcservClient
{
    public function __construct(protected Server $server) {}

    public function baseUrl(): string
    {
        $host = $this->server->apiConnectionHost();
        $port = (int) ($this->server->port ?: 9443);

        return sprintf('https://%s:%d', $host, $port);
    }

    /**
     * @return array{ok: bool, message: string, error?: string, api_url?: string}
     */
    public function testConnection(): array
    {
        try {
            $response = $this->request('get', '/api/health');

            if ($response->status() === 401) {
                return [
                    'ok' => false,
                    'message' => 'نام کاربری یا رمز پنل ocserv اشتباه است.',
                    'error' => 'unauthorized',
                    'api_url' => $this->baseUrl(),
                ];
            }

            if ($response->status() === 403) {
                return [
                    'ok' => false,
                    'message' => 'IP پنل برای API ocserv مجاز نیست.',
                    'error' => 'forbidden',
                    'api_url' => $this->baseUrl(),
                ];
            }

            if (! $response->successful() || ! ($response->json('ok') === true)) {
                return [
                    'ok' => false,
                    'message' => 'اتصال به ocserv ناموفق بود.',
                    'error' => $this->errorMessage($response),
                    'api_url' => $this->baseUrl(),
                ];
            }

            return [
                'ok' => true,
                'message' => 'اتصال به OpenConnect (ocserv) برقرار شد.',
                'api_url' => $this->baseUrl(),
            ];
        } catch (ConnectionException $exception) {
            Log::channel('ocserv')->warning('ocserv connection timeout', [
                'server_id' => $this->server->id,
                'host' => $this->server->host,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => 'سرور در دسترس نیست یا IP پنل مجاز نیست.',
                'error' => $exception->getMessage(),
                'api_url' => $this->baseUrl(),
            ];
        } catch (Throwable $exception) {
            Log::channel('ocserv')->warning('ocserv connection test failed', [
                'server_id' => $this->server->id,
                'host' => $this->server->host,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => 'اتصال به ocserv ناموفق بود.',
                'error' => $exception->getMessage(),
                'api_url' => $this->baseUrl(),
            ];
        }
    }

    /**
     * @return list<array{username: string, group: ?string, locked: bool, max_sessions: int|null}>
     */
    public function listUsers(): array
    {
        $response = $this->request('get', '/api/users');
        $this->assertSuccess($response, [200]);

        $users = $response->json('users');

        return is_array($users) ? array_values(array_filter($users, 'is_array')) : [];
    }

    /**
     * @return array{username: string, group: ?string, locked: bool, max_sessions: int|null}
     */
    public function getUser(string $username): array
    {
        $response = $this->request('get', '/api/users/'.rawurlencode($username));
        $this->assertSuccess($response, [200]);

        return $this->normalizeUser($response->json());
    }

    /**
     * @return array{username: string, group: ?string, locked: bool, max_sessions: int|null}
     */
    public function createUser(
        string $username,
        string $password,
        ?int $maxSessions = 1,
        ?string $group = null,
    ): array {
        $body = [
            'username' => $username,
            'password' => $password,
            'max_sessions' => $maxSessions,
            'group' => $group,
        ];

        $response = $this->request('post', '/api/users', $body);

        if ($response->status() === 409) {
            throw new RemoteProvisionException(
                'کاربر ocserv از قبل وجود دارد: '.$username
            );
        }

        $this->assertSuccess($response, [201]);

        return $this->normalizeUser($response->json());
    }

    /**
     * @return array{username: string, group: ?string, locked: bool, max_sessions: int|null}
     */
    public function setPassword(string $username, string $password): array
    {
        $response = $this->request('put', '/api/users/'.rawurlencode($username).'/password', [
            'password' => $password,
        ]);
        $this->assertSuccess($response, [200]);

        return $this->normalizeUser($response->json());
    }

    /**
     * @return array{username: string, group: ?string, locked: bool, max_sessions: int|null}
     */
    public function setLimits(string $username, ?int $maxSessions): array
    {
        $response = $this->request('put', '/api/users/'.rawurlencode($username).'/limits', [
            'max_sessions' => $maxSessions,
        ]);
        $this->assertSuccess($response, [200]);

        return $this->normalizeUser($response->json());
    }

    /**
     * @return array{username: string, group: ?string, locked: bool, max_sessions: int|null}
     */
    public function lockUser(string $username): array
    {
        $response = $this->request('post', '/api/users/'.rawurlencode($username).'/lock');
        $this->assertSuccess($response, [200]);

        return $this->normalizeUser($response->json());
    }

    /**
     * @return array{username: string, group: ?string, locked: bool, max_sessions: int|null}
     */
    public function unlockUser(string $username): array
    {
        $response = $this->request('post', '/api/users/'.rawurlencode($username).'/unlock');
        $this->assertSuccess($response, [200]);

        return $this->normalizeUser($response->json());
    }

    public function deleteUser(string $username): void
    {
        $response = $this->request('delete', '/api/users/'.rawurlencode($username));

        if ($response->status() === 404) {
            return;
        }

        $this->assertSuccess($response, [200]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSessions(): array
    {
        $response = $this->request('get', '/api/sessions');
        $this->assertSuccess($response, [200]);

        $sessions = $response->json('sessions');

        return is_array($sessions) ? array_values(array_filter($sessions, 'is_array')) : [];
    }

    public function disconnectUser(string $username): void
    {
        $response = $this->request('post', '/api/sessions/'.rawurlencode($username).'/disconnect');

        if ($response->status() === 404) {
            return;
        }

        $this->assertSuccess($response, [200]);
    }

    /**
     * @return array<string, mixed>
     */
    public function traffic(): array
    {
        $response = $this->request('get', '/api/traffic');
        $this->assertSuccess($response, [200]);

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function tunnels(): array
    {
        $response = $this->request('get', '/api/tunnels');
        $this->assertSuccess($response, [200]);

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    protected function request(string $method, string $path, ?array $json = null): Response
    {
        $url = $this->baseUrl().'/'.ltrim($path, '/');
        $attempts = 0;
        $maxAttempts = 2;

        beginning:
        $attempts++;
        try {
            $http = $this->http();

            return match (strtolower($method)) {
                'get' => $http->get($url),
                'post' => $http->post($url, $json ?? (object) []),
                'put' => $http->put($url, $json ?? (object) []),
                'delete' => $http->delete($url),
                default => throw new RemoteConnectionException('Unsupported HTTP method: '.$method),
            };
        } catch (ConnectionException $exception) {
            if ($attempts < $maxAttempts) {
                usleep(250_000);
                goto beginning;
            }

            throw new RemoteConnectionException(
                'سرور ocserv در دسترس نیست یا IP پنل مجاز نیست: '.$exception->getMessage(),
                previous: $exception
            );
        }
    }

    protected function http(): PendingRequest
    {
        $username = trim((string) ($this->server->username_enc ?? ''));
        $password = (string) ($this->server->password_enc ?? '');

        if ($username === '' || $password === '') {
            throw new RemoteConnectionException(
                'نام کاربری و رمز پنل ocserv الزامی است.'
            );
        }

        $timeout = max(10, (int) config('shahpanel.ocserv.timeout_seconds', 15));
        $verify = (bool) ($this->server->ocserv_verify_ssl ?? true);

        return Http::timeout($timeout)
            ->connectTimeout(min(10, $timeout))
            ->withBasicAuth($username, $password)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->withOptions(['verify' => $verify])
            ->acceptJson();
    }

    /**
     * @param  list<int>  $okStatuses
     */
    protected function assertSuccess(Response $response, array $okStatuses): void
    {
        if (in_array($response->status(), $okStatuses, true)) {
            return;
        }

        $message = $this->errorMessage($response);

        if ($response->status() === 401) {
            throw new RemoteConnectionException('نام کاربری یا رمز پنل ocserv اشتباه است.');
        }

        if ($response->status() === 403) {
            throw new RemoteConnectionException('IP پنل برای API ocserv مجاز نیست.');
        }

        if ($response->status() === 404) {
            throw new RemoteProvisionException('کاربر روی سرور ocserv یافت نشد.');
        }

        throw new RemoteProvisionException(
            'ocserv API ناموفق (HTTP '.$response->status().'): '.$message
        );
    }

    protected function errorMessage(Response $response): string
    {
        $error = $response->json('error');
        if (is_string($error) && $error !== '') {
            return $error;
        }

        return mb_substr(trim((string) $response->body()), 0, 300) ?: 'unknown error';
    }

    /**
     * @param  mixed  $payload
     * @return array{username: string, group: ?string, locked: bool, max_sessions: int|null}
     */
    protected function normalizeUser(mixed $payload): array
    {
        $data = is_array($payload) ? $payload : [];

        return [
            'username' => (string) ($data['username'] ?? ''),
            'group' => array_key_exists('group', $data) && $data['group'] !== null
                ? (string) $data['group']
                : null,
            'locked' => (bool) ($data['locked'] ?? false),
            'max_sessions' => array_key_exists('max_sessions', $data) && $data['max_sessions'] !== null
                ? (int) $data['max_sessions']
                : null,
        ];
    }
}
