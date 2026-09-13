<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Services\Sanaei\SanaeiPanelUrl;
use Tests\TestCase;

class SanaeiPanelUrlTest extends TestCase
{
    public function test_plain_host_uses_http_on_default_port(): void
    {
        $server = new Server(['host' => '10.0.0.5', 'port' => 0]);
        $url = SanaeiPanelUrl::fromServer($server);

        $this->assertSame('http://10.0.0.5:2053', $url->origin);
    }

    public function test_candidates_try_https_first_on_2053(): void
    {
        $server = new Server(['host' => 'panel.example.com', 'port' => 2053]);
        $candidates = SanaeiPanelUrl::candidatesFromServer($server);

        $this->assertNotEmpty($candidates);
        $this->assertSame('https://panel.example.com:2053', $candidates[0]->origin);
    }

    public function test_web_base_path_from_server_field(): void
    {
        $server = new Server([
            'host' => 'panel.example.com',
            'port' => 2053,
            'web_base_path' => '/secret',
        ]);
        $url = SanaeiPanelUrl::fromServer($server);

        $this->assertSame('/secret', $url->basePath);
        // A bare host carries no scheme, so fromServer() stays on http — exactly
        // what test_plain_host_uses_http_on_default_port pins down. Reaching
        // https on 2053 is candidatesFromServer()'s job, not this one's.
        $this->assertSame('http://panel.example.com:2053/secret/login', $url->route('/login'));
    }

    public function test_https_host_without_port_appends_configured_port(): void
    {
        $server = new Server(['host' => 'https://panel.example.com', 'port' => 2053]);
        $url = SanaeiPanelUrl::fromServer($server);

        $this->assertSame('https://panel.example.com:2053', $url->origin);
        $this->assertSame('https://panel.example.com:2053/panel/api/inbounds/list', $url->api('/panel/api', '/inbounds/list'));
    }

    public function test_https_host_with_path_and_port_in_url(): void
    {
        $server = new Server(['host' => 'https://panel.example.com:8443/secret/', 'port' => 2053]);
        $url = SanaeiPanelUrl::fromServer($server);

        $this->assertSame('https://panel.example.com:8443', $url->origin);
        $this->assertSame('/secret', $url->basePath);
        $this->assertSame('https://panel.example.com:8443/secret/login', $url->route('/login'));
    }

    public function test_from_location_header_parses_https_redirect(): void
    {
        $url = SanaeiPanelUrl::fromLocationHeader('https://panel1.example.com:2053/');

        $this->assertNotNull($url);
        $this->assertSame('https://panel1.example.com:2053', $url->origin);
    }
}
