<?php

namespace Tests\Unit\Tunneling;

use App\Enums\TunnelKind;
use App\Services\MikrotikService;
use App\Services\Tunneling\MtuCalculator;
use Tests\TestCase;

class MtuCalculatorTest extends TestCase
{
    protected function calculator(): MtuCalculator
    {
        return new MtuCalculator(new MikrotikService);
    }

    public function test_calculates_mtu_per_kind_from_overhead_table(): void
    {
        config(['tunneling.mtu.base' => 1500, 'tunneling.mtu.safety_margin' => 8]);

        $calc = $this->calculator();

        $this->assertSame(1500 - 24 - 8, $calc->calculate(TunnelKind::Gre, false));
        $this->assertSame(1500 - 44 - 8, $calc->calculate(TunnelKind::Gre6, false));
        $this->assertSame(1500 - 20 - 8, $calc->calculate(TunnelKind::Ipip, false));
        $this->assertSame(1500 - 42 - 8, $calc->calculate(TunnelKind::Eoip, false));
        $this->assertSame(1500 - 50 - 8, $calc->calculate(TunnelKind::Vxlan, false));
        $this->assertSame(1500 - 40 - 8, $calc->calculate(TunnelKind::L2tpV2, false));
        $this->assertSame(1500 - 28 - 8, $calc->calculate(TunnelKind::L2tpV3Ip, false));
        $this->assertSame(1500 - 36 - 8, $calc->calculate(TunnelKind::L2tpV3Udp, false));
    }

    public function test_ipsec_adds_overhead(): void
    {
        config(['tunneling.mtu.base' => 1500, 'tunneling.mtu.safety_margin' => 8]);

        $calc = $this->calculator();

        $this->assertSame(
            $calc->calculate(TunnelKind::Gre, false) - TunnelKind::ipsecOverheadBytes(),
            $calc->calculate(TunnelKind::Gre, true),
        );
    }

    public function test_respects_custom_underlay_mtu(): void
    {
        config(['tunneling.mtu.safety_margin' => 8]);

        $this->assertSame(1400 - 24 - 8, $this->calculator()->calculate(TunnelKind::Gre, false, 1400));
    }

    public function test_never_returns_below_minimum(): void
    {
        $this->assertSame(576, $this->calculator()->calculate(TunnelKind::Vxlan, true, 600));
    }
}
