<?php

namespace App\Services;

use App\Enums\ServerType;
use App\Exceptions\RemoteProvisionException;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Services\Ocserv\OcservClient;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * ocserv / OpenConnect provisioning, isolated from the Cisco ASA provider.
 *
 * All work goes through the HTTP JSON management API next to ocserv; changes
 * apply immediately, so there is no configuration-save step.
 */
class OcservService
{
    /** @var array<int, OcservClient> */
    protected array $clients = [];

    public function testConnection(Server $server): bool
    {
        return $this->testConnectionDetails($server)['ok'];
    }

    /**
     * @return array{ok: bool, message: string, error?: string, user_count?: int, api_url?: string}
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
                'message' => 'اتصال به سرویس مدیریتی ocserv ناموفق بود.',
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

        try {
            $this->client($server)->createUser(
                $username,
                $password,
                $this->resolvedGroup($server, $package) ?: null,
                $this->resolvedMaxSessions($server, $package),
            );
        } catch (Throwable $exception) {
            Log::channel('ocserv')->error('Failed to create ocserv user', [
                'server_id' => $server->id,
                'username' => $username,
                'error' => $exception->getMessage(),
            ]);

            throw new RemoteProvisionException(
                'ساخت کاربر OpenConnect روی سرور ocserv ناموفق بود: '.$exception->getMessage(),
                previous: $exception
            );
        }

        Log::channel('ocserv')->info('ocserv user provisioned', [
            'server_id' => $server->id,
            'username' => $username,
        ]);

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

        $username = $this->accountUsername($account);
        $password = (string) ($account->remote_password_enc ?? '');

        if ($username === '' || $password === '') {
            throw new RemoteProvisionException('نام کاربری یا رمز OpenConnect برای همگام‌سازی موجود نیست.');
        }

        $client = $this->client($server);

        try {
            $client->upsertUser(
                $username,
                $password,
                $this->resolvedGroup($server, $package) ?: null,
                $this->resolvedMaxSessions($server, $package),
            );

            if ($forceEnable || $account->status?->value === 'active') {
                $client->unlockUser($username);
            } else {
                $client->lockUser($username);
                $client->disconnectUser($username);
            }
        } catch (Throwable $exception) {
            Log::channel('ocserv')->error('Failed to sync ocserv user', [
                'account_id' => $account->id,
                'server_id' => $server->id,
                'username' => $username,
                'error' => $exception->getMessage(),
            ]);

            throw new RemoteProvisionException(
                'همگام‌سازی کاربر OpenConnect ناموفق بود: '.$exception->getMessage(),
                previous: $exception
            );
        }
    }

    public function setUserEnabled(Account $account, bool $enabled): void
    {
        $account->loadMissing(['server', 'package']);
        $server = $account->server;

        if ($server === null) {
            return;
        }

        $this->assertOcservServer($server);

        $username = $this->accountUsername($account);
        if ($username === '') {
            return;
        }

        $client = $this->client($server);

        try {
            if ($enabled) {
                $client->setMaxSessions(
                    $username,
                    $this->resolvedMaxSessions($server, $account->package),
                );
                $client->unlockUser($username);
            } else {
                $client->lockUser($username);
                $client->disconnectUser($username);
            }
        } catch (Throwable $exception) {
            Log::channel('ocserv')->error('Failed to toggle ocserv user', [
                'account_id' => $account->id,
                'username' => $username,
                'enabled' => $enabled,
                'error' => $exception->getMessage(),
            ]);

            throw new RemoteProvisionException(
                ($enabled ? 'فعال‌سازی' : 'غیرفعال‌سازی').' کاربر OpenConnect ناموفق: '.$exception->getMessage(),
                previous: $exception
            );
        }

        Log::channel('ocserv')->info('ocserv user toggled', [
            'account_id' => $account->id,
            'username' => $username,
            'enabled' => $enabled,
        ]);
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
                'حذف کاربر OpenConnect از سرور ocserv ناموفق: '.$exception->getMessage(),
                previous: $exception
            );
        }

        Log::channel('ocserv')->info('ocserv user removed', [
            'server_id' => $server->id,
            'username' => $username,
        ]);
    }

    /**
     * @return array{
     *     service_label: string,
     *     server_name: ?string,
     *     server_host: string,
     *     port: int,
     *     username: string,
     *     password: string,
     *     group: string,
     *     max_sessions: int,
     *     setup_guide_text: string
     * }
     */
    public function connectionDetails(Account $account): array
    {
        $account->loadMissing(['server', 'package']);
        $server = $account->server;
        $package = $account->package;

        $host = '';
        if ($server !== null) {
            $vpnHost = trim((string) ($server->ocserv_vpn_hostname ?? ''));
            $host = $vpnHost !== '' ? $vpnHost : $server->vpnClientEndpointHost();
        }

        $username = $this->accountUsername($account);
        $password = (string) ($account->remote_password_enc ?? '');
        $group = $this->resolvedGroup($server, $package);
        $maxSessions = $this->resolvedMaxSessions($server, $package);
        // Clients connect to ocserv itself on 443 — the management API port is
        // panel-side only and never handed to the customer.
        $port = 443;

        $guide = implode("\n", array_filter([
            'OpenConnect / Cisco AnyConnect',
            'Server Address: '.$host,
            'Port: '.$port.' (HTTPS)',
            'Username: '.$username,
            'Password: '.$password,
            $group !== '' ? 'Group: '.$group : null,
        ]));

        return [
            'service_label' => 'OpenConnect (ocserv)',
            'server_name' => $server?->name,
            'server_host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'group' => $group,
            'max_sessions' => $maxSessions,
            'setup_guide_text' => $guide,
        ];
    }

    protected function client(Server $server): OcservClient
    {
        $id = (int) $server->id;

        return $this->clients[$id] ??= new OcservClient($server);
    }

    protected function accountUsername(Account $account): string
    {
        return (string) ($account->ocserv_username ?: $account->remote_username);
    }

    protected function resolvedGroup(?Server $server, ?Package $package): string
    {
        $fromPackage = trim((string) ($package?->ocserv_group ?? ''));

        return $fromPackage !== ''
            ? $fromPackage
            : trim((string) ($server?->ocserv_group ?? ''));
    }

    protected function resolvedMaxSessions(?Server $server, ?Package $package): int
    {
        if ($package !== null && $package->ocserv_max_sessions !== null) {
            return max(0, (int) $package->ocserv_max_sessions);
        }

        if ($server !== null && $server->ocserv_max_sessions !== null) {
            return max(0, (int) $server->ocserv_max_sessions);
        }

        return max(0, (int) config('vpnpanel.ocserv.default_max_sessions', 1));
    }

    protected function assertOcservServer(Server $server): void
    {
        if ($server->type !== ServerType::Ocserv) {
            throw new InvalidArgumentException('این عملیات فقط برای سرور OpenConnect / ocserv است.');
        }
    }
}
