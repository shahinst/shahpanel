<?php

declare(strict_types=1);

namespace App\Services\Remnawave;

use App\Models\Server;

/**
 * Normalizes Remnawave node list cached on servers.
 */
final class RemnawaveNodeCatalog
{
    /**
     * @param  mixed  $raw
     * @return list<array{uuid: string, name: string, address: string, port: int|null, is_connected: bool}>
     */
    public static function normalizeList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $items = isset($raw['nodes']) && is_array($raw['nodes']) ? $raw['nodes'] : $raw;
        $normalized = [];

        foreach ($items as $node) {
            if (! is_array($node)) {
                continue;
            }

            $uuid = (string) ($node['uuid'] ?? $node['id'] ?? '');
            if ($uuid === '') {
                continue;
            }

            $name = (string) ($node['name'] ?? $node['title'] ?? $node['tag'] ?? $uuid);
            $address = (string) ($node['address'] ?? $node['host'] ?? $node['connectionAddress'] ?? '');
            $port = isset($node['port']) ? (int) $node['port'] : null;
            $isConnected = (bool) ($node['isConnected'] ?? $node['is_connected'] ?? $node['connected'] ?? false);

            $normalized[] = [
                'uuid' => $uuid,
                'name' => $name,
                'address' => $address,
                'port' => $port > 0 ? $port : null,
                'is_connected' => $isConnected,
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array{uuid: string, name: string, address: string, port: int|null, is_connected: bool}>
     */
    public static function forServer(Server $server): array
    {
        $stored = $server->remnawave_nodes;

        return is_array($stored) ? self::normalizeList($stored) : [];
    }
}
