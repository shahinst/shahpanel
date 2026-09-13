<?php

namespace Tests\Unit\RouterOs;

use App\Services\RouterOs\RouterWipeScript;
use PHPUnit\Framework\TestCase;

class RouterWipeScriptTest extends TestCase
{
    public function test_source_includes_comment_pattern_and_interface_orphans(): void
    {
        $source = RouterWipeScript::source('vpnl');

        $this->assertStringContainsString('comment~"vpnl"', $source);
        $this->assertStringContainsString('/ip/route remove', $source);
        $this->assertStringContainsString('name~"^vpnl-"', $source);
        $this->assertStringContainsString(':do {', $source);
        $this->assertStringContainsString('on-error={}', $source);
    }

    public function test_source_escapes_special_characters_in_pattern(): void
    {
        $source = RouterWipeScript::source('vpnl:tg1:');

        $this->assertStringContainsString('comment~"vpnl:tg1:"', $source);
    }

    public function test_for_tunnel_group_includes_group_patterns_and_firewall_fields(): void
    {
        $source = RouterWipeScript::forTunnelGroup(3, ['vpnl'], ['vpnl-tg3-e1-a1']);

        $this->assertStringContainsString('vpnl:tg3', $source);
        $this->assertStringContainsString('vpnl-tg3', $source);
        $this->assertStringContainsString('in-interface~"vpnl"', $source);
        $this->assertStringContainsString('name="vpnl-tg3-e1-a1"', $source);
    }
}
