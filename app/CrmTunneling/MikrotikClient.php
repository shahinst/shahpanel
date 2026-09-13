<?php

namespace App\CrmTunneling;

use App\Exceptions\RemoteConnectionException;
use App\Models\Server;
use App\Services\MikrotikService;
use RuntimeException;
use Throwable;

/**
 * Thin wrapper over MikrotikService for CRM tunnel drivers.
 */
class MikrotikClient
{
    public function __construct(protected MikrotikService $mikrotik)
    {
    }

    public function testConnection(Server $server): bool
    {
        return $this->mikrotik->testConnection($server);
    }

    /**
     * @param  array<string, string|int>  $filters
     * @return list<array<string, mixed>>
     */
    public function print(Server $server, string $menu, array $filters = []): array
    {
        return $this->mikrotik->queryRouter($server, rtrim($menu, '/').'/print', $filters);
    }

    /**
     * @param  array<string, string|int|bool>  $attributes
     * @return array<string, mixed>
     */
    public function add(Server $server, string $menu, array $attributes): array
    {
        return $this->mikrotik->sendCommand($server, rtrim($menu, '/').'/add', $attributes);
    }

    public function removeById(Server $server, string $menu, string $id): void
    {
        $this->mikrotik->sendCommand($server, rtrim($menu, '/').'/remove', ['.id' => $id]);
    }

    /**
     * Remove rows whose comment contains $needle (CRM-TUN cleanup).
     */
    public function removeWhereCommentContains(Server $server, string $menu, string $needle): int
    {
        return $this->mikrotik->removeWhereContains($server, rtrim($menu, '/'), 'comment', $needle);
    }

    /**
     * @param  array<string, string|int>  $attributes
     * @return list<array<string, mixed>>
     */
    public function ping(Server $server, array $attributes): array
    {
        try {
            $rows = $this->mikrotik->ping($server, $attributes);

            return is_array($rows) ? $rows : [$rows];
        } catch (Throwable $e) {
            throw new RemoteConnectionException('Ping failed: '.$e->getMessage(), 0, $e);
        }
    }

    public function hubPublicIp(Server $server): string
    {
        $ip = trim((string) ($server->public_ip ?: $server->apiConnectionHost()));

        if ($ip === '') {
            throw new RuntimeException("سرور «{$server->name}» public_ip ندارد.");
        }

        return $ip;
    }

    public function wanInterface(Server $server): string
    {
        return trim((string) ($server->wan_interface ?? 'ether1')) ?: 'ether1';
    }
}
