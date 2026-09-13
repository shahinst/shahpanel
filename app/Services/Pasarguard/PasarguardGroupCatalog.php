<?php

namespace App\Services\Pasarguard;

use App\Models\Server;

/**
 * Normalizes and reads PasarGuard group list cached on servers after connection test.
 */
final class PasarguardGroupCatalog
{
    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<array{id: int, name: string, inbound_tags: list<string>}>
     */
    public static function normalizeList(array $raw): array
    {
        $out = [];

        foreach ($raw as $group) {
            if (! is_array($group)) {
                continue;
            }

            $id = (int) ($group['id'] ?? $group['group_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $tags = $group['inbound_tags'] ?? [];
            if (! is_array($tags)) {
                $tags = [];
            }

            $out[] = [
                'id' => $id,
                'name' => (string) ($group['name'] ?? $group['title'] ?? ('Group #'.$id)),
                'inbound_tags' => array_values(array_filter(array_map('strval', $tags))),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: int, name: string, inbound_tags: list<string>}>
     */
    public static function forServer(Server $server): array
    {
        $stored = $server->pasarguard_groups;

        return is_array($stored) ? self::normalizeList($stored) : [];
    }

    public static function hasGroups(Server $server): bool
    {
        return self::forServer($server) !== [];
    }
}
