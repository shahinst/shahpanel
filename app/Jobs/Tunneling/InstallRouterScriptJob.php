<?php

namespace App\Jobs\Tunneling;

use App\Models\Server;
use App\Models\TunnelGroupEvent;
use App\Services\MikrotikService;
use App\Services\Tunneling\RouterScriptService;
use Throwable;

/**
 * Installs/updates the vpnl-probe script + its 10s scheduler on a router.
 * Checksum-based: identical versions are skipped.
 */
class InstallRouterScriptJob extends BaseTunnelingJob
{
    public function __construct(public int $serverId, public bool $force = false)
    {
        parent::__construct();
    }

    public function handle(RouterScriptService $scripts, MikrotikService $mikrotik): void
    {
        $server = Server::query()->find($this->serverId);

        if ($server === null || ! $server->isMikrotik()) {
            return;
        }

        $record = $scripts->record($server);
        $source = $scripts->buildScript($server);
        $checksum = $scripts->checksum($source);

        if (! $this->force && $record->checksum === $checksum && $record->status === 'installed') {
            return;
        }

        $this->withServerLock($server->id, function () use ($server, $mikrotik, $scripts, $record, $source, $checksum): void {
            try {
                // Script body.
                $existing = $mikrotik->queryRouter($server, '/system/script/print', ['name' => RouterScriptService::SCRIPT_NAME]);

                if ($existing === []) {
                    $mikrotik->sendCommand($server, '/system/script/add', [
                        'name' => RouterScriptService::SCRIPT_NAME,
                        'source' => $source,
                        'policy' => 'read,write,test',
                    ]);
                } else {
                    $mikrotik->sendCommand($server, '/system/script/set', [
                        '.id' => (string) $existing[0]['.id'],
                        'source' => $source,
                        'policy' => 'read,write,test',
                    ]);
                }

                // 10s scheduler.
                $interval = max(5, (int) config('tunneling.metrics.probe_interval', 10));
                $scheduler = $mikrotik->queryRouter($server, '/system/scheduler/print', ['name' => RouterScriptService::SCRIPT_NAME]);

                $payload = [
                    'name' => RouterScriptService::SCRIPT_NAME,
                    'on-event' => RouterScriptService::SCRIPT_NAME,
                    'interval' => "00:00:{$interval}",
                    'policy' => 'read,write,test',
                ];

                if ($scheduler === []) {
                    $mikrotik->sendCommand($server, '/system/scheduler/add', $payload);
                } else {
                    $mikrotik->sendCommand($server, '/system/scheduler/set', $payload + ['.id' => (string) $scheduler[0]['.id']]);
                }

                $record->update([
                    'version' => $record->version + 1,
                    'checksum' => $checksum,
                    'status' => 'installed',
                    'installed_at' => now(),
                    'last_error' => null,
                ]);

                TunnelGroupEvent::record('script_installed', "اسکریپت مانیتورینگ روی «{$server->name}» نصب/به‌روزرسانی شد (v{$record->version}).", [
                    'server_id' => $server->id,
                ]);
            } catch (Throwable $e) {
                $record->update([
                    'status' => 'error',
                    'last_error' => mb_substr($e->getMessage(), 0, 2000),
                ]);

                TunnelGroupEvent::record('script_install_failed', "نصب اسکریپت مانیتورینگ روی «{$server->name}» شکست خورد: ".$e->getMessage(), [
                    'server_id' => $server->id,
                ], 'error');
            }
        });
    }
}
