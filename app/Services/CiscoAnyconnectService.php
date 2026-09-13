<?php

namespace App\Services;

use App\Exceptions\RemoteProvisionException;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Services\CiscoAnyconnect\CiscoAnyconnectClient;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class CiscoAnyconnectService
{
    /** @var array<int, CiscoAnyconnectClient> */
    protected array $clients = [];

    public function testConnection(Server $server): bool
    {
        return $this->testConnectionDetails($server)['ok'];
    }

    /**
     * @return array{ok: bool, message: string, error?: string, asa_version?: string, user_count?: int, api_url?: string}
     */
    public function testConnectionDetails(Server $server): array
    {
        $this->assertCiscoServer($server);

        try {
            return $this->client($server)->testConnection();
        } catch (Throwable $exception) {
            Log::channel('cisco_anyconnect')->warning('Cisco AnyConnect connection test failed', [
                'server_id' => $server->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => 'اتصال به Cisco ASA / AnyConnect ناموفق بود.',
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{cisco_asa_username: string}
     */
    public function createVpnUser(
        Server $server,
        Package $package,
        string $username,
        string $password,
    ): array {
        $this->assertCiscoServer($server);

        try {
            $this->client($server)->provisionVpnUser(
                $username,
                $password,
                $this->provisionOptions($server, $package),
            );
        } catch (Throwable $exception) {
            Log::channel('cisco_anyconnect')->error('Failed to create AnyConnect user', [
                'server_id' => $server->id,
                'username' => $username,
                'error' => $exception->getMessage(),
            ]);

            throw new RemoteProvisionException(
                'ساخت کاربر Cisco AnyConnect روی ASA ناموفق بود: '.$exception->getMessage(),
                previous: $exception
            );
        }

        return ['cisco_asa_username' => $username];
    }

    public function syncVpnUser(Account $account, bool $forceEnable = false): void
    {
        $account->loadMissing(['server', 'package']);
        $server = $account->server;
        $package = $account->package;

        if ($server === null) {
            throw new InvalidArgumentException('اکانت به سرور Cisco متصل نیست.');
        }

        $this->assertCiscoServer($server);

        $username = (string) ($account->cisco_asa_username ?: $account->remote_username);
        $password = (string) ($account->remote_password_enc ?? '');

        if ($username === '' || $password === '') {
            throw new RemoteProvisionException('نام کاربری یا رمز AnyConnect برای همگام‌سازی موجود نیست.');
        }

        $options = $this->provisionOptions($server, $package);
        if (! ($forceEnable || $account->status?->value === 'active')) {
            $options['simultaneous_logins'] = 0;
        }

        $this->client($server)->provisionVpnUser($username, $password, $options);
    }

    public function setUserEnabled(Account $account, bool $enabled): void
    {
        $account->loadMissing(['server', 'package']);
        $server = $account->server;

        if ($server === null) {
            return;
        }

        $this->assertCiscoServer($server);

        $username = (string) ($account->cisco_asa_username ?: $account->remote_username);
        if ($username === '') {
            return;
        }

        try {
            $this->client($server)->setVpnUserEnabled(
                $username,
                $enabled,
                $this->resolvedSimultaneousLogins($server, $account->package),
                (bool) ($server->cisco_write_memory ?? true),
            );
        } catch (Throwable $exception) {
            Log::channel('cisco_anyconnect')->error('Failed to toggle AnyConnect user', [
                'account_id' => $account->id,
                'enabled' => $enabled,
                'error' => $exception->getMessage(),
            ]);

            throw new RemoteProvisionException(
                ($enabled ? 'فعال‌سازی' : 'غیرفعال‌سازی').' کاربر AnyConnect ناموفق: '.$exception->getMessage(),
                previous: $exception
            );
        }
    }

    public function removeVpnUser(Server $server, string $username): void
    {
        if ($username === '') {
            return;
        }

        $this->assertCiscoServer($server);

        try {
            $this->client($server)->removeVpnUser(
                $username,
                (bool) ($server->cisco_write_memory ?? true),
            );
        } catch (Throwable $exception) {
            Log::channel('cisco_anyconnect')->error('Failed to remove AnyConnect user', [
                'server_id' => $server->id,
                'username' => $username,
                'error' => $exception->getMessage(),
            ]);

            throw new RemoteProvisionException(
                'حذف کاربر AnyConnect از ASA ناموفق: '.$exception->getMessage(),
                previous: $exception
            );
        }
    }

    /**
     * @return array{
     *     service_label: string,
     *     server_name: ?string,
     *     server_host: string,
     *     port: int,
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
        $package = $account->package;

        $host = '';
        if ($server !== null) {
            $vpnHost = trim((string) ($server->cisco_vpn_hostname ?? ''));
            $host = $vpnHost !== '' ? $vpnHost : $server->vpnClientEndpointHost();
        }

        $username = (string) ($account->cisco_asa_username ?: $account->remote_username);
        $password = (string) ($account->remote_password_enc ?? '');
        $group = $this->resolvedGroupPolicy($server, $package);
        $tunnel = $this->resolvedTunnelGroup($server, $package);
        $port = 443;

        $guide = implode("\n", array_filter([
            'Cisco AnyConnect / Secure Client',
            'Server Address: '.$host,
            'Port: '.$port.' (HTTPS)',
            'Username: '.$username,
            'Password: '.$password,
            $group !== '' ? 'Group Policy: '.$group : null,
            $tunnel !== '' ? 'Tunnel Group / Connection Profile: '.$tunnel : null,
        ]));

        return [
            'service_label' => 'Cisco AnyConnect',
            'server_name' => $server?->name,
            'server_host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'group_policy' => $group,
            'tunnel_group' => $tunnel,
            'setup_guide_text' => $guide,
        ];
    }

    protected function client(Server $server): CiscoAnyconnectClient
    {
        $id = (int) $server->id;

        return $this->clients[$id] ??= new CiscoAnyconnectClient($server);
    }

    /**
     * @return array{group_policy: ?string, tunnel_group: ?string, simultaneous_logins: int, write_memory: bool}
     */
    protected function provisionOptions(Server $server, ?Package $package): array
    {
        return [
            'group_policy' => $this->resolvedGroupPolicy($server, $package) ?: null,
            'tunnel_group' => $this->resolvedTunnelGroup($server, $package) ?: null,
            'simultaneous_logins' => $this->resolvedSimultaneousLogins($server, $package),
            'write_memory' => (bool) ($server->cisco_write_memory ?? true),
        ];
    }

    protected function resolvedGroupPolicy(?Server $server, ?Package $package): string
    {
        $fromPackage = trim((string) ($package?->cisco_group_policy ?? ''));

        return $fromPackage !== ''
            ? $fromPackage
            : trim((string) ($server?->cisco_group_policy ?? ''));
    }

    protected function resolvedTunnelGroup(?Server $server, ?Package $package): string
    {
        $fromPackage = trim((string) ($package?->cisco_tunnel_group ?? ''));

        return $fromPackage !== ''
            ? $fromPackage
            : trim((string) ($server?->cisco_tunnel_group ?? ''));
    }

    protected function resolvedSimultaneousLogins(?Server $server, ?Package $package): int
    {
        if ($package !== null && $package->cisco_simultaneous_logins !== null) {
            return max(0, (int) $package->cisco_simultaneous_logins);
        }

        return max(0, (int) ($server?->cisco_simultaneous_logins ?? 1));
    }

    protected function assertCiscoServer(Server $server): void
    {
        if (! $server->isCiscoAnyconnect()) {
            throw new InvalidArgumentException('این عملیات فقط برای سرور Cisco AnyConnect است.');
        }
    }
}
