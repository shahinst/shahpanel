<?php

namespace Modules\Dedicated\Services;

use App\Services\MikrotikService;
use Illuminate\Support\Facades\DB;
use Modules\Dedicated\Models\DedicatedServer;
use Throwable;

/**
 * Reads how much a dedicated agent's MikroTik has downloaded, from the
 * counter of the interface that faces the internet.
 *
 * Only download is counted: rx on the uplink is what the customers pulled
 * in. The router keeps that counter in memory, so a reboot or an interface
 * reset drops it to zero; a counter lower than the last reading is taken as a
 * fresh start, so the stored total only ever grows.
 */
class UsageMeter
{
    public function __construct(protected MikrotikService $mikrotik) {}

    /** @return array<string, int> interfaces of the router and their download counter */
    public function interfaces(DedicatedServer $row): array
    {
        $out = [];

        foreach ($this->mikrotik->queryRouter($row->server, '/interface/print') as $iface) {
            $name = (string) ($iface['name'] ?? '');

            if ($name !== '') {
                $out[$name] = (int) ($iface['rx-byte'] ?? 0);
            }
        }

        ksort($out);

        return $out;
    }

    public function read(DedicatedServer $row): bool
    {
        $row->loadMissing('server');

        if ($row->meter_interface === null || $row->server === null || ! $row->server->isMikrotik()) {
            return false;
        }

        try {
            $rows = $this->mikrotik->queryRouter($row->server, '/interface/print', ['name' => $row->meter_interface]);
        } catch (Throwable $e) {
            $row->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 250)])->save();

            return false;
        }

        if ($rows === [] || ! isset($rows[0]['rx-byte'])) {
            $row->forceFill(['last_error' => __('dedicated::admin.meter_interface_missing', ['name' => $row->meter_interface])])->save();

            return false;
        }

        $this->record($row, (int) $rows[0]['rx-byte']);

        return true;
    }

    /** Fold one counter reading into the totals. */
    public function record(DedicatedServer $row, int $counter): int
    {
        // The first reading only sets the baseline: what the router counted
        // before metering began is not this period's usage.
        $delta = match (true) {
            $row->last_counter === null => 0,
            $counter < $row->last_counter => $counter,
            default => $counter - $row->last_counter,
        };

        DB::transaction(function () use ($row, $counter, $delta): void {
            $row->forceFill([
                'last_counter' => $counter,
                'total_rx_bytes' => $row->total_rx_bytes + $delta,
                'last_read_at' => now(),
                'last_error' => null,
            ])->save();

            if ($delta > 0) {
                DB::table('dedicated_usage_days')->insertOrIgnore(['server_id' => $row->server_id, 'day' => today()->toDateString(), 'rx_bytes' => 0]);
                DB::table('dedicated_usage_days')
                    ->where('server_id', $row->server_id)->where('day', today()->toDateString())
                    ->increment('rx_bytes', $delta);
            }
        });

        return $delta;
    }
}
