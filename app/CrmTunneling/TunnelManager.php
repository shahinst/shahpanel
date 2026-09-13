<?php

namespace App\CrmTunneling;

use App\CrmTunneling\Contracts\TunnelDriver;
use App\CrmTunneling\Drivers\GreTunnelDriver;
use App\CrmTunneling\Dto\TunnelTestResult;
use App\Enums\CrmTunnelLogAction;
use App\Enums\CrmTunnelStatus;
use App\Enums\CrmTunnelType;
use App\Models\CrmTunnel;
use App\Models\CrmTunnelLog;
use App\Models\Server;
use RuntimeException;
use Throwable;

class TunnelManager
{
    /** @var array<string, class-string<TunnelDriver>> */
    protected array $drivers = [
        'gre' => GreTunnelDriver::class,
    ];

    public function __construct(
        protected MikrotikClient $client,
    ) {
    }

    public function driver(CrmTunnel $tunnel): TunnelDriver
    {
        $class = $this->drivers[$tunnel->type->value] ?? null;

        if ($class === null) {
            throw new RuntimeException("No driver for tunnel type {$tunnel->type->value}");
        }

        return app($class);
    }

    public function deploy(CrmTunnel $tunnel): void
    {
        $tunnel->update(['status' => CrmTunnelStatus::Provisioning]);

        $driver = $this->driver($tunnel);
        $tunnel->loadMissing('hubServer', 'exitServer');

        try {
            $this->logStep($tunnel, CrmTunnelLogAction::Reset, $tunnel->exitServer, $driver->reset($tunnel->exitServer));
            $this->logStep($tunnel, CrmTunnelLogAction::Reset, $tunnel->hubServer, $driver->reset($tunnel->hubServer));
            $this->logStep($tunnel, CrmTunnelLogAction::Provision, null, $driver->provision($tunnel));

            $result = $driver->test($tunnel);
            $this->logStep($tunnel, CrmTunnelLogAction::Test, $tunnel->hubServer, [$result->toArray()], $result->passed);

            $tunnel->update([
                'status' => $result->passed ? CrmTunnelStatus::Active : CrmTunnelStatus::Failed,
                'last_result' => $result->toArray(),
                'last_tested_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->logStep($tunnel, CrmTunnelLogAction::Provision, null, [$e->getMessage()], false);
            $tunnel->update(['status' => CrmTunnelStatus::Failed]);

            throw $e;
        }
    }

    public function testOnly(CrmTunnel $tunnel): TunnelTestResult
    {
        $result = $this->driver($tunnel)->test($tunnel);

        $this->logStep($tunnel, CrmTunnelLogAction::Test, $tunnel->hubServer, [$result->toArray()], $result->passed);

        $tunnel->update([
            'last_result' => $result->toArray(),
            'last_tested_at' => now(),
            'status' => $result->passed ? CrmTunnelStatus::Active : CrmTunnelStatus::Down,
        ]);

        return $result;
    }

    public function teardown(CrmTunnel $tunnel): void
    {
        $logs = $this->driver($tunnel)->teardown($tunnel);
        $this->logStep($tunnel, CrmTunnelLogAction::Teardown, null, $logs, true);
    }

    /**
     * @param  list<mixed>  $output
     */
    protected function logStep(
        CrmTunnel $tunnel,
        CrmTunnelLogAction $action,
        ?Server $server,
        array $output,
        bool $success = true,
    ): void {
        CrmTunnelLog::create([
            'crm_tunnel_id' => $tunnel->id,
            'action' => $action,
            'server_id' => $server?->id,
            'success' => $success,
            'output' => implode("\n", array_map(
                fn ($line) => is_string($line) ? $line : json_encode($line, JSON_UNESCAPED_UNICODE),
                $output,
            )),
            'created_at' => now(),
        ]);
    }
}
