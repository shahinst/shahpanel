<?php

namespace Tests\Unit\Support;

use App\Support\LegacyTunnelNameResolver;
use PHPUnit\Framework\TestCase;

class LegacyTunnelNameResolverTest extends TestCase
{
    public function test_needles_include_legacy_interface_prefix(): void
    {
        $needles = LegacyTunnelNameResolver::needles(1, 5);

        $this->assertContains('t1f', $needles);
        $this->assertContains('tunnel-t1', $needles);
        $this->assertContains('mgd:5:fw-tunnel-in', $needles);
        $this->assertContains('TUNNELS', $needles);
    }
}
