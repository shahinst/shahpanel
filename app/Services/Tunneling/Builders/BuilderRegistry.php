<?php

namespace App\Services\Tunneling\Builders;

use App\Enums\TunnelKind;

class BuilderRegistry
{
    public function for(TunnelKind $kind): TunnelBuilder
    {
        return match ($kind) {
            TunnelKind::Gre => new GreBuilder,
            TunnelKind::Gre6 => new Gre6Builder,
            TunnelKind::Ipip => new IpipBuilder,
            TunnelKind::Eoip => new EoipBuilder,
            TunnelKind::Vxlan => new VxlanBuilder,
            TunnelKind::L2tpV2 => new L2tpV2Builder,
            TunnelKind::L2tpV3Udp => new L2tpV3Builder('udp'),
            TunnelKind::L2tpV3Ip => new L2tpV3Builder('ip'),
            TunnelKind::Sixto4 => new Sixto4Builder,
        };
    }
}
