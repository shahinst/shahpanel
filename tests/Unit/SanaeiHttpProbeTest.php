<?php

namespace Tests\Unit;

use App\Services\Sanaei\SanaeiHttpProbe;
use PHPUnit\Framework\TestCase;

class SanaeiHttpProbeTest extends TestCase
{
    public function test_detect_https_upgrade_from_http_zero_response(): void
    {
        $this->markTestSkipped('Requires live panel host — run manually against standard1.vpline.online:2053');
    }

    public function test_raw_get_rejects_non_http_scheme(): void
    {
        $result = SanaeiHttpProbe::rawGet('https://example.com:2053/');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('raw probe', $result['error'] ?? '');
    }
}
