<?php

namespace App\Services\Tunneling;

/**
 * 0–100 quality score per agent. Detects THROTTLING, not just downtime: the
 * throughput component compares current rate against the agent's recorded
 * healthy baseline, so a tunnel that "works" at 5% of its usual speed scores
 * badly and triggers a switch.
 */
class QualityScoreService
{
    public function score(
        ?float $latencyMs,
        ?float $jitterMs,
        ?float $lossPct,
        ?float $throughputRatio,
    ): float {
        $weights = (array) config('tunneling.scoring.weights', []);
        $wLatency = (float) ($weights['latency'] ?? 30);
        $wJitter = (float) ($weights['jitter'] ?? 15);
        $wLoss = (float) ($weights['loss'] ?? 35);
        $wThroughput = (float) ($weights['throughput'] ?? 20);

        $latencyRef = max(1, (int) config('tunneling.scoring.latency_ref_ms', 300));
        $jitterRef = max(1, (int) config('tunneling.scoring.jitter_ref_ms', 80));

        $total = 0.0;
        $weightSum = 0.0;

        if ($latencyMs !== null) {
            $total += $wLatency * max(0.0, 1.0 - min($latencyMs, $latencyRef * 2) / ($latencyRef * 2));
            $weightSum += $wLatency;
        }

        if ($jitterMs !== null) {
            $total += $wJitter * max(0.0, 1.0 - min($jitterMs, $jitterRef * 2) / ($jitterRef * 2));
            $weightSum += $wJitter;
        }

        if ($lossPct !== null) {
            // Loss hurts hard: 10% loss ≈ halves the component, 30%+ zeroes it.
            $total += $wLoss * max(0.0, 1.0 - ($lossPct / 30.0));
            $weightSum += $wLoss;
        }

        if ($throughputRatio !== null) {
            $total += $wThroughput * max(0.0, min(1.0, $throughputRatio));
            $weightSum += $wThroughput;
        }

        if ($weightSum <= 0.0) {
            return 0.0;
        }

        return round(($total / $weightSum) * 100, 2);
    }

    /** Whether the throughput ratio alone indicates throttling. */
    public function isThrottled(?float $throughputRatio): bool
    {
        if ($throughputRatio === null) {
            return false;
        }

        return $throughputRatio < (float) config('tunneling.scoring.throttle_ratio', 0.35);
    }
}
