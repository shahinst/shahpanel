<?php

namespace Tests\Unit;

use App\Services\SanaeiService;
use PHPUnit\Framework\TestCase;

class SanaeiTrafficSnapshotTest extends TestCase
{
    public function test_total_field_is_limit_not_usage(): void
    {
        $service = new SanaeiService;
        $oneGb = 1024 * 1024 * 1024;

        $snapshot = $service->normalizeTrafficSnapshot([
            'up' => 150_000_000,
            'down' => 50_000_000,
            'total' => $oneGb,
        ]);

        $this->assertSame(200_000_000, $snapshot['used_bytes']);
        $this->assertSame($oneGb, $snapshot['limit_bytes']);
        $this->assertSame($oneGb - 200_000_000, $snapshot['remaining_bytes']);
        $this->assertSame(200_000_000, $snapshot['total']);
        $this->assertSame(150_000_000, $snapshot['down']);
        $this->assertSame(50_000_000, $snapshot['up']);
        $this->assertSame(150_000_000, $snapshot['download_bytes']);
        $this->assertSame(50_000_000, $snapshot['upload_bytes']);
    }

    public function test_zero_usage_with_limit(): void
    {
        $service = new SanaeiService;
        $twoGb = 2 * 1024 * 1024 * 1024;

        $snapshot = $service->normalizeTrafficSnapshot([
            'up' => 0,
            'down' => 0,
            'total' => $twoGb,
        ]);

        $this->assertSame(0, $snapshot['used_bytes']);
        $this->assertSame($twoGb, $snapshot['limit_bytes']);
        $this->assertSame($twoGb, $snapshot['remaining_bytes']);
    }
}
