<?php

namespace App\Jobs\Tunneling;

use App\Models\Server;
use App\Models\TunnelGroupEvent;
use App\Services\RouterOs\DesiredStateApplier;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Drift detection + (optional) auto-repair for one router: every applied
 * desired object is compared against the actual router row; drifted objects
 * are re-applied when auto_repair is enabled.
 */
class ReconcileServerJob extends BaseTunnelingJob implements ShouldBeUnique
{
    public int $uniqueFor = 120;

    public function __construct(
        public int $serverId,
        public bool $repair = true,
        public bool $highPriority = false,
    ) {
        parent::__construct();

        if (! $highPriority) {
            $this->onQueue((string) config('tunneling.queue.low_name', 'tunneling-low'));
        }
    }

    public function uniqueId(): string
    {
        return 'reconcile:'.$this->serverId.':'.($this->repair ? '1' : '0');
    }

    public function handle(DesiredStateApplier $applier): void
    {
        $server = Server::query()->find($this->serverId);

        if ($server === null || ! $server->isMikrotik()) {
            return;
        }

        $this->withServerLock($server->id, function () use ($server, $applier): void {
            $drifted = $applier->detectDrift($server);

            if ($drifted === []) {
                return;
            }

            TunnelGroupEvent::record(
                'drift_detected',
                count($drifted).' آبجکت دچار drift روی «'.$server->name.'» — '.collect($drifted)->pluck('marker')->take(10)->implode(', '),
                ['server_id' => $server->id],
                'warning',
            );

            if ($this->repair && (bool) config('tunneling.reconcile.auto_repair', true)) {
                $stats = $applier->applyForServer($server);

                TunnelGroupEvent::record(
                    'drift_repaired',
                    'ترمیم drift روی «'.$server->name.'»: '.$stats['created'].' ساخت، '.$stats['updated'].' اصلاح، '.$stats['failed'].' خطا.',
                    ['server_id' => $server->id],
                    $stats['failed'] > 0 ? 'warning' : 'ok',
                );
            }
        });
    }
}
