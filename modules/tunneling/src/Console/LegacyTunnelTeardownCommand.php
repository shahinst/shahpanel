<?php

namespace Modules\Tunneling\Console;

use App\Models\Server;
use App\Services\MikrotikService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One-shot teardown of the LEGACY tunneling system from live routers.
 *
 * Self-contained on purpose: reads the old tunnel tables directly via the DB
 * facade and uses only MikrotikService primitives, so it keeps working after
 * every legacy tunnel service/model has been deleted from the codebase.
 *
 * Run BEFORE migrating (the drop-tables migration removes the data this
 * command needs to find router-side objects).
 *
 *   php artisan tunnels:legacy-teardown            (dry-run)
 *   php artisan tunnels:legacy-teardown --apply    (remove from routers)
 */
class LegacyTunnelTeardownCommand extends Command
{
    protected $signature = 'tunnels:legacy-teardown
        {--apply : Actually remove objects from routers (default is dry-run)}
        {--tunnel= : Limit to a single legacy tunnel ID}';

    protected $description = 'Remove all legacy tunnel objects (interfaces, routes, mangle, NAT, firewall) from MikroTik routers';

    /** Menus swept by tunnel comment marker (tunnel-t{id}). */
    private const COMMENT_SWEEP_MENUS = [
        '/ip/firewall/mangle',
        '/ip/firewall/nat',
        '/ip/firewall/filter',
        '/ip/firewall/raw',
        '/ip/firewall/address-list',
        '/ip/route',
        '/routing/rule',
        '/ppp/secret',
        '/ip/ipsec/peer',
        '/ip/ipsec/identity',
        '/ip/ipsec/policy',
    ];

    /** Interface menus where legacy link interfaces may live. */
    private const INTERFACE_MENUS = [
        '/interface/gre',
        '/interface/gre6',
        '/interface/ipip',
        '/interface/ipipv6',
        '/interface/eoip',
        '/interface/vxlan',
        '/interface/l2tp-client',
        '/interface/l2tp-server',
        '/interface/sstp-client',
        '/interface/sstp-server',
        '/interface/6to4',
    ];

    public function handle(MikrotikService $mikrotik): int
    {
        if (! Schema::hasTable('tunnels')) {
            $this->info('جدول tunnels وجود ندارد — چیزی برای teardown نیست.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $tunnels = DB::table('tunnels')
            ->when($this->option('tunnel'), fn ($q, $id) => $q->where('id', (int) $id))
            ->get();

        if ($tunnels->isEmpty()) {
            $this->info('هیچ تانل قدیمی در دیتابیس نیست.');

            return self::SUCCESS;
        }

        $this->warn($apply ? '== حالت اعمال (APPLY) ==' : '== حالت پیش‌نمایش (dry-run) — برای اجرا --apply بزنید ==');

        $failures = 0;

        foreach ($tunnels as $tunnel) {
            $this->line('');
            $this->info("تانل #{$tunnel->id} «{$tunnel->name}»");

            $exits = Schema::hasTable('tunnel_exits')
                ? DB::table('tunnel_exits')->where('tunnel_id', $tunnel->id)->get()
                : collect();
            $links = Schema::hasTable('tunnel_links')
                ? DB::table('tunnel_links')->where('tunnel_id', $tunnel->id)->get()
                : collect();

            $serverIds = collect([$tunnel->iran_server_id])
                ->merge($exits->pluck('foreign_server_id'))
                ->filter()
                ->unique();

            foreach ($serverIds as $serverId) {
                $server = Server::query()->find($serverId);

                if ($server === null) {
                    $this->line("  - سرور #{$serverId}: در پنل نیست — رد شد.");
                    continue;
                }

                $isIran = (int) $serverId === (int) $tunnel->iran_server_id;
                $interfaceNames = $links
                    ->map(fn ($l) => $isIran ? $l->iran_interface_name : $l->foreign_interface_name)
                    ->filter()
                    ->unique()
                    ->values();

                try {
                    $removed = $this->teardownOnServer(
                        $mikrotik,
                        $server,
                        (int) $tunnel->id,
                        $interfaceNames->all(),
                        $isIran ? (string) $tunnel->wg_interface_name : null,
                        $isIran ? (string) ($tunnel->routing_table_name ?? '') : null,
                        $apply,
                    );

                    $this->line("  - {$server->name} (#{$server->id}): ".($apply ? "{$removed} آبجکت حذف شد." : "{$removed} آبجکت پیدا شد (حذف نشده)."));
                } catch (Throwable $exception) {
                    $failures++;
                    $this->error("  - {$server->name} (#{$server->id}): خطا — ".$exception->getMessage());
                }
            }
        }

        $this->line('');

        if ($failures > 0) {
            $this->warn("پایان با {$failures} خطا — سرورهای خطادار را دستی بررسی کنید.");

            return self::FAILURE;
        }

        $this->info($apply
            ? 'Teardown کامل شد. حالا می‌توانید migrate (حذف جدول‌های قدیمی) را اجرا کنید.'
            : 'پیش‌نمایش کامل شد. برای حذف واقعی: php artisan tunnels:legacy-teardown --apply');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $interfaceNames
     */
    protected function teardownOnServer(
        MikrotikService $mikrotik,
        Server $server,
        int $tunnelId,
        array $interfaceNames,
        ?string $wgInterfaceName,
        ?string $routingTableName,
        bool $apply,
    ): int {
        $marker = "tunnel-t{$tunnelId}";
        $removed = 0;

        foreach (self::COMMENT_SWEEP_MENUS as $menu) {
            $removed += $this->sweep($mikrotik, $server, $menu, 'comment', $marker, contains: true, apply: $apply);
        }

        // Legacy managed firewall markers (mgd:{serverId}:fw-tunnel-in:...).
        foreach (['/ip/firewall/filter', '/ip/firewall/raw'] as $menu) {
            $removed += $this->sweep($mikrotik, $server, $menu, 'comment', "mgd:{$server->id}:fw-tunnel-in", contains: true, apply: $apply);
        }

        foreach ($interfaceNames as $name) {
            $removed += $this->sweep($mikrotik, $server, '/ip/address', 'interface', $name, contains: false, apply: $apply);
            $removed += $this->sweep($mikrotik, $server, '/ipv6/address', 'interface', $name, contains: false, apply: $apply);

            foreach (self::INTERFACE_MENUS as $menu) {
                $removed += $this->sweep($mikrotik, $server, $menu, 'name', $name, contains: false, apply: $apply);
            }
        }

        if ($wgInterfaceName !== null && $wgInterfaceName !== '') {
            $removed += $this->sweep($mikrotik, $server, '/ip/address', 'interface', $wgInterfaceName, contains: false, apply: $apply);
            $removed += $this->sweep($mikrotik, $server, '/interface/wireguard', 'name', $wgInterfaceName, contains: false, apply: $apply);
        }

        if ($routingTableName !== null && $routingTableName !== '') {
            $removed += $this->sweep($mikrotik, $server, '/routing/table', 'name', $routingTableName, contains: false, apply: $apply);
        }

        return $removed;
    }

    protected function sweep(
        MikrotikService $mikrotik,
        Server $server,
        string $menu,
        string $field,
        string $value,
        bool $contains,
        bool $apply,
    ): int {
        try {
            if (! $apply) {
                $rows = $mikrotik->queryRouter($server, $menu.'/print');

                return collect($rows)->filter(function (array $row) use ($field, $value, $contains): bool {
                    $current = (string) ($row[$field] ?? '');

                    return $contains ? str_contains($current, $value) : $current === $value;
                })->count();
            }

            return $contains
                ? $mikrotik->removeWhereContains($server, $menu, $field, $value)
                : $mikrotik->removeMatching($server, $menu, $field, $value);
        } catch (Throwable) {
            // Menu may not exist on this RouterOS version/package — skip silently.
            return 0;
        }
    }
}
