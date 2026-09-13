<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves legacy tunnel naming (t1f0-gre61-u, tunnel-t1-*, TUNNELS list, …)
 * from dropped-or-present tunnel_* tables, or from deterministic patterns.
 */
final class LegacyTunnelNameResolver
{
    /** Legacy address-list names from TunnelAddressAllocator. */
    public const TUNNELS_LIST = 'TUNNELS';

    public const NO_TUNNEL_LIST = 'NO_TUNNEL';

    public const LOOPBACK_BRIDGE = 'lo-tunnel';

    /**
     * Substrings that identify legacy tunnel config for tunnel/group id N.
     *
     * @return list<string>
     */
    public static function needles(int $tunnelOrGroupId, int $serverId): array
    {
        $id = $tunnelOrGroupId;

        return array_values(array_unique(array_filter([
            "t{$id}f",              // t1f0-gre61-u
            "tunnel-t{$id}",        // tunnel-t1-mark-wg
            "tunnel-t{$id}f",       // tunnel-t1f0-notunnel
            "mgd:{$serverId}:fw-tunnel-in",
            self::TUNNELS_LIST,
            self::NO_TUNNEL_LIST,
            self::LOOPBACK_BRIDGE,
        ])));
    }

    /**
     * Exact interface / resource names from legacy DB when still available.
     *
     * @return list<string>
     */
    public static function resourceNames(int $tunnelOrGroupId): array
    {
        $names = [];

        if (Schema::hasTable('tunnel_links')) {
            $links = DB::table('tunnel_links')
                ->where('tunnel_id', $tunnelOrGroupId)
                ->get(['iran_interface_name', 'foreign_interface_name']);

            foreach ($links as $link) {
                if ($link->iran_interface_name !== '') {
                    $names[] = (string) $link->iran_interface_name;
                }
                if ($link->foreign_interface_name !== '') {
                    $names[] = (string) $link->foreign_interface_name;
                }
            }
        }

        if (Schema::hasTable('tunnels')) {
            $tunnel = DB::table('tunnels')->where('id', $tunnelOrGroupId)->first();

            if ($tunnel !== null) {
                foreach (['wg_interface_name', 'routing_table_name'] as $column) {
                    $value = (string) ($tunnel->{$column} ?? '');

                    if ($value !== '') {
                        $names[] = $value;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    public static function legacyTableExists(): bool
    {
        return Schema::hasTable('tunnels') && Schema::hasTable('tunnel_links');
    }
}
