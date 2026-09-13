<?php

namespace App\Services\RouterOs;

use App\Models\ManagedInterface;
use App\Models\Server;
use App\Models\TunnelGroup;
use App\Models\TunnelGroupEvent;
use App\Services\MikrotikService;
use App\Services\Tunneling\RouterScriptService;
use App\Support\LegacyTunnelNameResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Removes ALL panel tunnel config from MikroTik — new (vpnl:…) and legacy
 * (t1f0-gre61-u, tunnel-t1-*, mgd:…, TUNNELS address-list, …).
 */
class RouterManagedWipeService
{
    /** Firewall: scan every field (rules often have no comment). */
    private const FIREWALL_MENUS = [
        '/ip/firewall/mangle',
        '/ip/firewall/nat',
        '/ip/firewall/filter',
        '/ip/firewall/raw',
        '/ip/firewall/address-list',
    ];

    private const COMMENT_MENUS = [
        '/routing/rule',
        '/ip/route',
        '/ip/ipsec/policy',
        '/ip/ipsec/identity',
        '/ip/ipsec/peer',
        '/ppp/secret',
        '/ppp/profile',
        '/ip/pool',
        '/system/scheduler',
        '/system/script',
        '/routing/table',
        '/interface/list/member',
    ];

    private const NAME_MENUS = [
        '/ip/pool',
        '/ppp/secret',
        '/ppp/profile',
        '/ip/ipsec/peer',
        '/routing/table',
        '/interface/bridge',
        '/interface/wireguard',
    ];

    /** @var list<string> */
    private const INTERFACE_MENUS = [
        '/interface/gre',
        '/interface/gre6',
        '/interface/ipip',
        '/interface/ipipv6',
        '/interface/eoip',
        '/interface/vxlan',
        '/interface/l2tp-client',
        '/interface/l2tp-server',
        '/interface/l2tp-ether',
        '/interface/wireguard',
        '/interface/sstp-client',
        '/interface/sstp-server',
        '/interface/6to4',
    ];

    public function __construct(
        protected MikrotikService $mikrotik,
        protected RouterCommandService $commands,
    ) {
    }

    /**
     * @param  callable(string): void|null  $progress
     * @return array{removed: int, errors: int, servers: list<string>}
     */
    public function wipeGroupRouters(
        TunnelGroup $group,
        bool $entireServer = true,
        ?callable $progress = null,
    ): array {
        $group->loadMissing('iranServer', 'exits.server', 'exits.agents');

        $servers = collect([$group->iranServer])
            ->merge($group->exits->map->server)
            ->filter()
            ->unique('id');

        $removed = 0;
        $errors = 0;
        $names = [];
        $log = $progress ?? static function (string $message): void {
            Log::info('tunneling: wipe '.$message);
        };

        if (Schema::hasTable('tunnels') && Schema::hasTable('tunnel_links')) {
            $legacyExists = \Illuminate\Support\Facades\DB::table('tunnels')
                ->where('id', $group->id)
                ->exists();

            if ($legacyExists) {
                $log('legacy DB found — running tunnels:legacy-teardown --apply');
                try {
                    Artisan::call('tunnels:legacy-teardown', [
                        '--apply' => true,
                        '--tunnel' => $group->id,
                    ]);
                    $output = trim(Artisan::output());

                    if ($output !== '') {
                        foreach (explode("\n", $output) as $line) {
                            $log('  '.trim($line));
                        }
                    }
                } catch (Throwable $e) {
                    $errors++;
                    $log('  legacy teardown error: '.$e->getMessage());
                }
            }
        }

        foreach ($servers as $server) {
            $names[] = $server->name;
            $log("→ {$server->name} …");

            try {
                $result = $this->wipeServer($server, $group, $log);
                $removed += $result['removed'];
                $errors += $result['errors'];
                $log("  {$server->name}: {$result['removed']} removed".($result['errors'] > 0 ? ", {$result['errors']} errors" : ''));
            } catch (Throwable $e) {
                $errors++;
                $log("  {$server->name}: ERROR — ".$e->getMessage());
                Log::error('tunneling: wipe server failed', [
                    'server_id' => $server->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        TunnelGroupEvent::record(
            'wipe',
            "پاک‌سازی روترهای گروه «{$group->name}»: {$removed} آبجکت حذف شد".($errors > 0 ? "، {$errors} خطا" : '').'.',
            [
                'tunnel_group_id' => $group->id,
                'servers' => $names,
                'method' => 'script+filtered-api',
            ],
            $errors > 0 ? 'warning' : 'ok',
        );

        return ['removed' => $removed, 'errors' => $errors, 'servers' => $names];
    }

    /**
     * Fast path: one ephemeral RouterOS script + filtered API sweeps.
     *
     * @param  callable(string): void  $log
     * @return array{removed: int, errors: int, method: string}
     */
    public function wipeServer(Server $server, TunnelGroup $group, ?callable $log = null): array
    {
        $errors = 0;
        $removed = 0;
        $log ??= static function (string $message): void {
            Log::info('tunneling: wipe '.$message);
        };

        $needles = $this->allNeedles($server, $group);
        $exactNames = $this->allExactNames($group);
        $managedIds = ManagedInterface::query()
            ->where('tunnel_group_id', $group->id)
            ->pluck('id')
            ->all();

        foreach ($managedIds as $miId) {
            $needles[] = config('tunneling.marker_prefix', 'vpnl').":mi{$miId}";
        }

        $needles = array_values(array_unique(array_filter($needles)));

        $log('  fast bulk script (RouterOS on-device) …');

        try {
            $script = RouterWipeScript::forTunnelGroup(
                $group->id,
                $needles,
                $exactNames,
            );
            $this->mikrotik->runEphemeralScript($server, $script, 240);
            $removed += 50;
        } catch (Throwable $e) {
            $errors++;
            $log('  bulk script error: '.$e->getMessage());
            Log::warning('tunneling: bulk wipe script failed', [
                'server_id' => $server->id,
                'group_id' => $group->id,
                'error' => $e->getMessage(),
            ]);
        }

        $log('  filtered API sweep …');

        foreach ([...self::COMMENT_MENUS, ...self::FIREWALL_MENUS] as $menu) {
            foreach ($needles as $needle) {
                try {
                    $pattern = preg_quote($needle, '/');
                    $count = $this->commands->removeRowsMatchingComment($server, $menu, $pattern);
                    $removed += $count;
                } catch (Throwable) {
                    $errors++;
                }
            }
        }

        foreach (self::NAME_MENUS as $menu) {
            foreach ($needles as $needle) {
                try {
                    $count = $this->commands->removeRowsMatchingName(
                        $server,
                        $menu,
                        preg_quote($needle, '/'),
                    );
                    $removed += $count;
                } catch (Throwable) {
                    $errors++;
                }
            }
        }

        foreach ($exactNames as $name) {
            foreach (self::INTERFACE_MENUS as $menu) {
                try {
                    $removed += $this->mikrotik->removeMatching($server, $menu, 'name', $name);
                } catch (Throwable) {
                    $errors++;
                }
            }
        }

        foreach (self::FIREWALL_MENUS as $menu) {
            foreach ($needles as $needle) {
                try {
                    $count = $this->safeRemoveAnyField($server, $menu, $needle, $errors);
                    $removed += $count;
                } catch (Throwable) {
                    $errors++;
                }
            }
        }

        $log("  done: ~{$removed} removed".($errors > 0 ? ", {$errors} errors" : ''));

        return ['removed' => $removed, 'errors' => $errors, 'method' => 'script+filtered-api'];
    }

    /**
     * @return array<string, int>
     */
    public function auditServer(Server $server, TunnelGroup $group): array
    {
        $needles = $this->allNeedles($server, $group);
        $counts = [];

        foreach ([...self::FIREWALL_MENUS, ...self::COMMENT_MENUS] as $menu) {
            $counts[$menu] = 0;

            try {
                $rows = $this->mikrotik->queryRouter($server, rtrim($menu, '/').'/print');
            } catch (Throwable) {
                continue;
            }

            foreach ($rows as $row) {
                if ($this->rowMatchesNeedles($row, $needles)) {
                    $counts[$menu]++;
                }
            }
        }

        foreach (self::INTERFACE_MENUS as $menu) {
            $label = $menu.' (if)';
            $counts[$label] = 0;

            try {
                $rows = $this->mikrotik->queryRouter($server, rtrim($menu, '/').'/print');
            } catch (Throwable) {
                continue;
            }

            foreach ($rows as $row) {
                if ($this->rowMatchesNeedles($row, $needles)) {
                    $counts[$label]++;
                }
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    protected function allNeedles(Server $server, TunnelGroup $group): array
    {
        $prefix = (string) config('tunneling.marker_prefix', 'vpnl');

        return array_values(array_unique(array_filter([
            ...LegacyTunnelNameResolver::needles($group->id, $server->id),
            $prefix,
            "{$prefix}:tg{$group->id}",
            "vpnl-tg{$group->id}",
            ...(array) config('tunneling.wipe.extra_comment_needles', []),
        ])));
    }

    /**
     * @return list<string>
     */
    protected function allExactNames(TunnelGroup $group): array
    {
        $names = LegacyTunnelNameResolver::resourceNames($group->id);

        foreach ($group->exits as $exit) {
            foreach ($exit->agents as $agent) {
                if ($agent->iran_interface !== '') {
                    $names[] = $agent->iran_interface;
                }
                if ($agent->foreign_interface !== '' && $agent->foreign_interface !== $agent->iran_interface) {
                    $names[] = $agent->foreign_interface;
                }
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $needles
     */
    protected function rowMatchesNeedles(array $row, array $needles): bool
    {
        foreach ($row as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            foreach ($needles as $needle) {
                if (str_contains($value, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function safeRemoveAnyField(
        Server $server,
        string $menu,
        string $needle,
        int &$errors,
    ): int {
        try {
            return $this->mikrotik->removeWhereAnyFieldContains($server, $menu, $needle);
        } catch (Throwable $e) {
            $errors++;
            Log::warning('tunneling: wipe any-field failed', [
                'server_id' => $server->id,
                'menu' => $menu,
                'needle' => $needle,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    protected function safeRemoveContains(
        Server $server,
        string $menu,
        string $field,
        string $needle,
        int &$errors,
    ): int {
        try {
            return $this->mikrotik->removeWhereContains($server, $menu, $field, $needle);
        } catch (Throwable $e) {
            $errors++;
            Log::warning('tunneling: wipe contains failed', [
                'server_id' => $server->id,
                'menu' => $menu,
                'field' => $field,
                'needle' => $needle,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    protected function safeRemoveExact(
        Server $server,
        string $menu,
        string $field,
        string $value,
        int &$errors,
    ): int {
        if ($value === '') {
            return 0;
        }

        try {
            return $this->mikrotik->removeMatching($server, $menu, $field, $value);
        } catch (Throwable $e) {
            $errors++;
            Log::warning('tunneling: wipe exact failed', [
                'server_id' => $server->id,
                'menu' => $menu,
                'field' => $field,
                'value' => $value,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
