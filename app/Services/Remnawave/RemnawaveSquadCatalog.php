<?php

namespace App\Services\Remnawave;

use App\Models\Server;

/**
 * Normalizes and reads the Remnawave internal-squad list cached on servers
 * after a successful connection test. Squad UUIDs are used in
 * `activeInternalSquads` when creating panel users.
 */
final class RemnawaveSquadCatalog
{
    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<array{uuid: string, name: string}>
     */
    public static function normalizeList(array $raw): array
    {
        $out = [];

        foreach ($raw as $squad) {
            if (! is_array($squad)) {
                continue;
            }

            $uuid = (string) ($squad['uuid'] ?? $squad['id'] ?? '');
            if ($uuid === '') {
                continue;
            }

            $out[] = [
                'uuid' => $uuid,
                'name' => (string) ($squad['name'] ?? $squad['title'] ?? $uuid),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{uuid: string, name: string}>
     */
    public static function forServer(Server $server): array
    {
        $stored = $server->remnawave_squads;

        return is_array($stored) ? self::normalizeList($stored) : [];
    }

    public static function hasSquads(Server $server): bool
    {
        return self::forServer($server) !== [];
    }

    /**
     * @return list<string>
     */
    public static function activeUuidsForServer(Server $server): array
    {
        return $server->remnawaveActiveSquadUuids();
    }
}
