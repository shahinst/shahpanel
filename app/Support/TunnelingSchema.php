<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Runtime checks for the tunneling desired-state schema on shared hosting
 * where migrations may lag behind code deploys.
 */
final class TunnelingSchema
{
    /** @return list<string> */
    public static function requiredTables(): array
    {
        return [
            'locations',
            'tunnel_groups',
            'tunnel_group_exits',
            'tunnel_agents',
            'desired_network_objects',
            'config_versions',
            'tunnel_group_events',
            'ip_pools',
            'ip_pool_allocations',
            'managed_interfaces',
            'router_scripts',
            'tunnel_metric_samples',
            'tunnel_metric_rollups',
            'server_metric_samples',
        ];
    }

    /** @return list<string> */
    public static function missingTables(): array
    {
        return array_values(array_filter(
            self::requiredTables(),
            fn (string $table): bool => ! Schema::hasTable($table),
        ));
    }

    public static function isReady(): bool
    {
        return self::missingTables() === [];
    }
}
