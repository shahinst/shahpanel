<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Services\SanaeiService;
use PHPUnit\Framework\TestCase;

class SanaeiSubscriptionLinkTest extends TestCase
{
    public function test_joins_sub_id_to_configured_sub_uri(): void
    {
        $service = new class extends SanaeiService
        {
            public function join(string $base, string $subId): string
            {
                return $this->joinSubscriptionPath($base, $subId);
            }

            public function defaultUri(Server $server, array $settings): string
            {
                return $this->resolveDefaultSubUri($server, $settings);
            }
        };

        $this->assertSame(
            'https://sub.example.com/path/abc123',
            $service->join('https://sub.example.com/path/', 'abc123')
        );

        $this->assertSame(
            'https://sub.example.com/path/abc123',
            $service->join('https://sub.example.com/path', 'abc123')
        );
    }

    public function test_builds_default_sub_uri_from_panel_settings(): void
    {
        $service = new class extends SanaeiService
        {
            public function defaultUri(Server $server, array $settings): string
            {
                return $this->resolveDefaultSubUri($server, $settings);
            }
        };

        $server = new Server([
            'host' => 'https://standard1.vpline.online:2053/s8F5ZEUJCen2nyoNvZ',
            'port' => 2053,
        ]);

        $uri = $service->defaultUri($server, [
            'subPort' => 2096,
            'subPath' => '/sub/',
            'subDomain' => '',
            'subKeyFile' => '',
            'subCertFile' => '',
        ]);

        $this->assertSame('https://standard1.vpline.online:2096/sub/', $uri);
    }

    public function test_sorts_subscription_candidates_https_first(): void
    {
        $service = new class extends SanaeiService
        {
            public function sort(array $urls): array
            {
                return $this->sortSubscriptionCandidatesHttpsFirst($urls);
            }
        };

        $sorted = $service->sort([
            'http://a.example/sub/1',
            'https://b.example/sub/1',
            'http://c.example/sub/1',
        ]);

        $this->assertSame('https://b.example/sub/1', $sorted[0]);
        $this->assertStringStartsWith('http://', $sorted[1]);
    }

    public function test_parses_plain_subscription_links(): void
    {
        $service = new class extends SanaeiService
        {
            public function parse(string $body, bool $encrypted): array
            {
                return $this->parseSubscriptionLinks($body, $encrypted);
            }
        };

        $plain = "vless://uuid@1.2.3.4:443?type=ws#remark\nvmess://abc";

        $this->assertCount(2, $service->parse($plain, false));
    }

    public function test_parses_base64_subscription_links(): void
    {
        $service = new class extends SanaeiService
        {
            public function parse(string $body, bool $encrypted): array
            {
                return $this->parseSubscriptionLinks($body, $encrypted);
            }
        };

        $encoded = base64_encode("vless://uuid@1.2.3.4:443?type=ws#remark");

        $this->assertSame(
            ['vless://uuid@1.2.3.4:443?type=ws#remark'],
            $service->parse($encoded, true)
        );
    }
}
