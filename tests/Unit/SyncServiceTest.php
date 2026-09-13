<?php

namespace Tests\Unit;

use App\Services\AccountService;
use App\Services\MikrotikService;
use App\Services\SanaeiService;
use App\Services\SyncService;
use PHPUnit\Framework\TestCase;

class SyncServiceTest extends TestCase
{
    private SyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SyncService(
            $this->createMock(MikrotikService::class),
            $this->createMock(SanaeiService::class),
            $this->createMock(AccountService::class),
        );
    }

    public function test_compute_counter_delta_returns_difference_for_monotonic_counters(): void
    {
        $this->assertSame(500, $this->service->computeCounterDelta(1_000, 1_500));
    }

    public function test_compute_counter_delta_treats_reboot_reset_as_current_usage(): void
    {
        $this->assertSame(250, $this->service->computeCounterDelta(9_500, 250));
    }

    public function test_compute_counter_delta_handles_equal_snapshots(): void
    {
        $this->assertSame(0, $this->service->computeCounterDelta(4_000, 4_000));
    }

    public function test_compute_counter_delta_handles_first_reading_after_reboot_from_zero(): void
    {
        $this->assertSame(0, $this->service->computeCounterDelta(8_000, 0));
    }

    public function test_compute_delta_returns_rx_and_tx_deltas(): void
    {
        $delta = $this->service->computeDelta(
            ['rx_bytes' => 1_000, 'tx_bytes' => 2_000],
            ['rx_bytes' => 1_400, 'tx_bytes' => 2_100]
        );

        $this->assertSame([
            'rx_delta_bytes' => 400,
            'tx_delta_bytes' => 100,
        ], $delta);
    }

    public function test_compute_delta_detects_reboot_on_either_direction(): void
    {
        $delta = $this->service->computeDelta(
            ['rx_snapshot' => 5_000, 'tx_snapshot' => 8_000],
            ['rx_snapshot' => 120, 'tx_snapshot' => 45]
        );

        $this->assertSame([
            'rx_delta_bytes' => 120,
            'tx_delta_bytes' => 45,
        ], $delta);
    }

    public function test_compute_delta_supports_mixed_snapshot_key_names(): void
    {
        $delta = $this->service->computeDelta(
            ['rx' => 100, 'tx' => 200],
            ['rx_bytes' => 160, 'tx_bytes' => 260]
        );

        $this->assertSame([
            'rx_delta_bytes' => 60,
            'tx_delta_bytes' => 60,
        ], $delta);
    }

    public function test_compute_delta_treats_missing_snapshot_values_as_zero(): void
    {
        $delta = $this->service->computeDelta(
            [],
            ['rx_bytes' => 75, 'tx_bytes' => 25]
        );

        $this->assertSame([
            'rx_delta_bytes' => 75,
            'tx_delta_bytes' => 25,
        ], $delta);
    }

    public function test_should_not_mark_exhausted_when_panel_has_headroom_against_local_limit(): void
    {
        $method = new \ReflectionMethod(SyncService::class, 'shouldMarkQuotaExhausted');
        $method->setAccessible(true);

        $account = new \App\Models\Account([
            'data_limit_bytes' => 10 * 1024 * 1024 * 1024,
            'data_used_bytes' => 10 * 1024 * 1024 * 1024,
        ]);

        $meta = [
            'normalized' => [
                'used_bytes' => 5 * 1024 * 1024 * 1024,
                'limit_bytes' => 5 * 1024 * 1024 * 1024,
            ],
        ];

        $this->assertFalse($method->invoke($this->service, $account, $meta));
    }

    public function test_resolve_absolute_used_bytes_prefers_normalized_total(): void
    {
        $method = new \ReflectionMethod(SyncService::class, 'resolveAbsoluteUsedBytes');
        $method->setAccessible(true);

        $used = $method->invoke($this->service, [
            'rx_bytes' => 999,
            'tx_bytes' => 999,
        ], [
            'normalized' => [
                'used_bytes' => 4_000,
                'limit_bytes' => 8_000,
            ],
        ]);

        $this->assertSame(4_000, $used);
    }
}
