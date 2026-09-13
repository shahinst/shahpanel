<?php

namespace App\Services\Tunneling;

use InvalidArgumentException;

/**
 * Splits a client-facing CIDR subnet into N contiguous, (almost) equal
 * address ranges — one per exit/location — matching the static
 * "FILTER-2>128 / FILTER-129>254" split style used for MikroTik
 * src-address range mangle rules (no PCC hashing involved).
 *
 * Reserves the network address (.0) and the gateway (.1) at the start of
 * the subnet, and the broadcast address at the end; only host addresses in
 * between are distributed across blocks. Any remainder is added to the
 * earliest blocks first (e.g. a /24 split two ways yields 2-128 / 129-254).
 */
class IpRangePartitioner
{
    /**
     * @return list<array{start: string, end: string, range: string, size: int}>
     */
    public function partition(string $cidr, int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Number of parts must be at least 1.');
        }

        [$networkIp, $prefixRaw] = array_pad(explode('/', trim($cidr), 2), 2, null);
        $prefix = (int) $prefixRaw;

        if ($networkIp === null || $prefix < 1 || $prefix > 30) {
            throw new InvalidArgumentException("Invalid CIDR for range partitioning: [{$cidr}].");
        }

        $networkLong = ip2long($networkIp);

        if ($networkLong === false) {
            throw new InvalidArgumentException("Invalid network address in CIDR: [{$cidr}].");
        }

        $hostBits = 32 - $prefix;
        $totalAddresses = 2 ** $hostBits;
        $broadcastLong = $networkLong + $totalAddresses - 1;

        // Skip network (.0) and gateway (.1); stop before broadcast.
        $startLong = $networkLong + 2;
        $endLong = $broadcastLong - 1;

        if ($startLong > $endLong) {
            throw new InvalidArgumentException("Subnet [{$cidr}] has no usable host range to split.");
        }

        $usableCount = $endLong - $startLong + 1;
        $parts = min($parts, $usableCount);

        $base = intdiv($usableCount, $parts);
        $remainder = $usableCount % $parts;

        $blocks = [];
        $cursor = $startLong;

        for ($i = 0; $i < $parts; $i++) {
            $size = $base + ($i < $remainder ? 1 : 0);
            $blockStart = $cursor;
            $blockEnd = $cursor + $size - 1;

            $blocks[] = [
                'start' => long2ip($blockStart),
                'end' => long2ip($blockEnd),
                'range' => long2ip($blockStart).'-'.long2ip($blockEnd),
                'size' => $size,
            ];

            $cursor = $blockEnd + 1;
        }

        return $blocks;
    }
}
