<?php

namespace App\Services\Tunneling;

use App\Enums\TunnelKind;
use App\Models\Server;
use App\Services\MikrotikService;
use Throwable;

/**
 * MTU management: theoretical per-kind overhead table + real DF-bit probe of
 * the underlay path executed ON the Iran router (binary search over ping
 * sizes). probed underlay PMTU − encapsulation overhead = tunnel MTU.
 */
class MtuCalculator
{
    public function __construct(protected MikrotikService $mikrotik)
    {
    }

    /**
     * Theoretical tunnel MTU from the overhead table (no probe).
     */
    public function calculate(TunnelKind $kind, bool $ipsec, ?int $underlayMtu = null): int
    {
        $base = $underlayMtu ?? (int) config('tunneling.mtu.base', 1500);
        $overhead = $kind->overheadBytes() + ($ipsec ? TunnelKind::ipsecOverheadBytes() : 0);
        $margin = (int) config('tunneling.mtu.safety_margin', 8);

        return max(576, $base - $overhead - $margin);
    }

    /**
     * Probe the real underlay PMTU from $from to $targetIp with DF pings
     * (binary search). Returns the largest passing IP packet size, or null
     * when even the minimum fails (path down / ICMP filtered).
     */
    public function probeUnderlayMtu(Server $from, string $targetIp): ?int
    {
        $min = (int) config('tunneling.mtu.probe_min', 1200);
        $max = (int) config('tunneling.mtu.base', 1500);

        if (! $this->pingWithSize($from, $targetIp, $min)) {
            return null;
        }

        $low = $min;       // known good
        $high = $max + 1;  // known bad + 1

        while ($low + 1 < $high) {
            $mid = intdiv($low + $high, 2);

            if ($this->pingWithSize($from, $targetIp, $mid)) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    /**
     * Probed tunnel MTU: underlay PMTU − overhead (no extra margin — the probe
     * already reflects the real path).
     */
    public function probedTunnelMtu(TunnelKind $kind, bool $ipsec, Server $from, string $targetIp): ?int
    {
        $underlay = $this->probeUnderlayMtu($from, $targetIp);

        if ($underlay === null) {
            return null;
        }

        $overhead = $kind->overheadBytes() + ($ipsec ? TunnelKind::ipsecOverheadBytes() : 0);

        return max(576, $underlay - $overhead);
    }

    /**
     * One DF ping at a given total IP packet size ($size includes the 28-byte
     * IP+ICMP header — RouterOS `size` parameter semantics).
     */
    protected function pingWithSize(Server $from, string $targetIp, int $size): bool
    {
        try {
            $rows = $this->mikrotik->sendCommand($from, '/ping', [
                'address' => $targetIp,
                'count' => '2',
                'size' => (string) $size,
                'do-not-fragment' => '',
                'interval' => '0.5',
            ]);
        } catch (Throwable) {
            return false;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $received = (int) ($row['received'] ?? 0);
            $status = (string) ($row['status'] ?? '');

            if ($received > 0 && $status === '') {
                return true;
            }

            if (isset($row['time']) && ($row['packet-loss'] ?? '100') !== '100' && $status === '') {
                return true;
            }
        }

        return false;
    }
}
