<?php

namespace App\Services;

use App\Exceptions\RemoteProvisionException;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Services\Ocserv\OcservClient;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class OcservService
{
    /** @var array<int, OcservClient> */
    protected array $clients = [];

    public function testConnection(Server $server): bool
    {
        return $this->testConnectionDetails($server)['ok'];
    }

    /**
     * @return array{ok: bool, message: string, error?: string, api_url?: string}
     */
    public function testConnectionDetails(Server $server): array
    {
        $this->assertOcservServer($server);

        try {
            return $this->client($server)->testConnection();
        } catch (Throwable $exception) {
            Log::channel('ocserv')->warning('ocserv connection test failed', [
                'server_id' => $server->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => 'اتصال به OpenConnect (ocserv) ناموفق بود.',
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{ocserv_username: string}
     */
    public function createVpnUser(
        Server $server,
        Package $package,
        string $username,
        string $password,
    ): array {
        $this->assertOcservServer($server);
        $this->assertUsername($username);
        $this->assertPassword($password);

        $maxSessions = $this->resolvedMaxSessions($server, $package);
        $group = $this->resolvedGroup($server, $package);

        try {
            $this->client($server)->createUser($username, $password, $maxSessions, $group);
        } catch (Throwable $exception) {
            Log::channel('ocserv')->error('Failed to create ocserv user', [
                'server_id' => $server->id,
                'username' => $username,
                'error' => $exception->getMessage(),
            ]);

            throw new RemoteProvisionException(
                'ساخت کاربر OpenConnect (ocserv) ناموفق بود: '.$exception->getMessage(),
                previous: $exception
            );
        }

        return ['ocserv_username' => $username];
    }

    public function syncVpnUser(Account $account, bool $forceEnable = false): void
    {
        $account->loadMissing(['server', 'package']);
        $server = $account->server;
        $package = $account->package;

        if ($server === null) {
            throw new InvalidArgumentException('اکانت به سرور ocserv متصل نیست.');
        }

        $this->assertOcservServer($server);

        $username = (string) ($account->remote_username ?? '');
        $password = (string) ($account->remote_password_enc ?? '');

        if ($username === '' || $password === '') {
            throw new RemoteProvisionException('نام کاربری یا رمز ocserv برای همگام‌سازی موجود نیست.');
        }

        $this->assertUsername($username);
        $this->assertPassword($password);

        $client = $this->client($server);
        $maxSessions = $this->resolvedMaxSessions($server, $package);
        $enabled = $forceEnable || $account->status?->value === 'active';

        try {
            try {
                $client->getUser($username);
                $client->setPassword($username, $password);
                $client->setLimits($username, $maxSessions);
            } catch (RemoteProvisionException $exception) {
                if (! str_contains($exception->getMessage(), 'یافت نشد')) {
                    throw $exception;
                }
                $client->createUser(
                    $username,
                    $password,
                    $maxSessions,
                    $this->resolvedGroup($server, $package),
                );
            }

            if ($enabled) {
                $client->unlockUser($username);
            } else {
                $client->lockUser($username);
            }
        } catch (Throwable $exception) {
            Log::channel('ocserv')->error('Failed to sync ocserv user', [
                'account_id' => $account->id,
                'username' => $username,
                'error' => $exception->getMessage(),
            ]);

            throw new RemoteProvisionException(
                'همگام‌سازی کاربر ocserv ناموفق: '.$exception->getMessage(),
                previous: $exception
            );
        }
    }

    public function setUserEnabled(Account $account, bool $enabled): void
    {
        $account->loadMissing('server');
        $server = $account->server;

        if ($server === null) {
            return;
        }

        $this->assertOcservServer($server);

        $username = (string) ($account->remote_username ?? '');
        if ($username === '') {
            return;
        }

        try {
            if ($enabled) {
                $this->client($server)->unlockUser($username);
            } else {
                $this->client($server)->lockUser($username);
            }
        } catch (Throwable $exception) {
            Log::channel('ocserv')->error('Failed to toggle ocserv user', [
                'account_id' => $account->id,
                'enabled' => $enabled,
                'error' => $exception->getMessage(),
            ]);

            throw new RemoteProvisionException(
                ($enabled ? 'فعال‌سازی' : 'غیرفعال‌سازی').' کاربر ocserv ناموفق: '.$exception->getMessage(),
                previous: $exception
            );
        }
    }

    public function removeVpnUser(Server $server, string $username): void
    {
        if ($username === '') {
            return;
        }

        $this->assertOcservServer($server);

        try {
            $this->client($server)->deleteUser($username);
        } catch (Throwable $exception) {
            Log::channel('ocserv')->error('Failed to remove ocserv user', [
                'server_id' => $server->id,
                'username' => $username,
                'error' => $exception->getMessage(),
            ]);

            throw new RemoteProvisionException(
                'حذف کاربر ocserv ناموفق: '.$exception->getMessage(),
                previous: $exception
            );
        }
    }

    /**
     * @return array{
     *     service_label: string,
     *     server_name: ?string,
     *     server_host: string,
     *     port: ?int,
     *     show_port: bool,
     *     username: string,
     *     password: string,
     *     group_policy: string,
     *     tunnel_group: string,
     *     setup_guide_text: string
     * }
     */
    public function connectionDetails(Account $account): array
    {
        $account->loadMissing(['server', 'package']);
        $server = $account->server;

        $host = '';
        if ($server !== null) {
            $vpnHost = trim((string) ($server->ocserv_vpn_address ?? ''));
            $host = $vpnHost !== '' ? $vpnHost : $server->vpnClientEndpointHost();
            if ($host === '') {
                $host = (string) $server->host;
            }
        }

        $username = (string) ($account->remote_username ?? '');
        $password = (string) ($account->remote_password_enc ?? '');

        $guide = implode("\n", array_filter([
            'Cisco Secure Client (AnyConnect) یا OpenConnect',
            'Server Address: '.$host,
            'Username: '.$username,
            'Password: '.$password,
        ]));

        return [
            'service_label' => 'OpenConnect (ocserv)',
            'server_name' => $server?->name,
            'server_host' => $host,
            'port' => null,
            'show_port' => false,
            'username' => $username,
            'password' => $password,
            'group_policy' => '',
            'tunnel_group' => '',
            'setup_guide_text' => $guide,
        ];
    }

    protected function client(Server $server): OcservClient
    {
        $id = (int) $server->id;

        return $this->clients[$id] ??= new OcservClient($server);
    }

    protected function resolvedMaxSessions(Server $server, ?Package $package): int
    {
        if ($package !== null && $package->ocserv_max_sessions !== null) {
            return max(0, min(1000, (int) $package->ocserv_max_sessions));
        }

        return max(0, min(1000, (int) ($server->ocserv_default_max_sessions ?? 1)));
    }

    protected function resolvedGroup(Server $server, ?Package $package): ?string
    {
        $fromPackage = trim((string) ($package?->ocserv_group ?? ''));
        if ($fromPackage !== '') {
            return $fromPackage;
        }

        $fromServer = trim((string) ($server->ocserv_group ?? ''));

        return $fromServer !== '' ? $fromServer : null;
    }

    protected function assertUsername(string $username): void
    {
        if (preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $username) !== 1) {
            throw new InvalidArgumentException(
                'نام کاربری ocserv نامعتبر است (فقط حروف، عدد، . _ @ - و حداکثر ۶۴ کاراکتر).'
            );
        }
    }

    protected function assertPassword(string $password): void
    {
        $len = strlen($password);
        if ($len < 1 || $len > 128 || str_contains($password, "\n") || str_contains($password, "\r")) {
            throw new InvalidArgumentException('رمز عبور ocserv نامعتبر است.');
        }
    }

    protected function assertOcservServer(Server $server): void
    {
        if (! $server->isOcserv()) {
            throw new InvalidArgumentException('این عملیات فقط برای سرور OpenConnect (ocserv) است.');
        }
    }
}
