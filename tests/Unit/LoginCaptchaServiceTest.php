<?php

namespace Tests\Unit;

use App\Services\LoginCaptchaService;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

class LoginCaptchaServiceTest extends TestCase
{
    public function test_issue_stores_svg_for_image_endpoint(): void
    {
        $session = new Store('test', new ArraySessionHandler(120));
        $request = Request::create('/login', 'GET');
        $request->setLaravelSession($session);

        $service = new LoginCaptchaService;
        $payload = $service->issue($request);

        $this->assertNotEmpty($payload['token']);
        $this->assertStringContainsString('<svg', $payload['svg']);

        $svg = $service->svgForToken($request, $payload['token']);

        $this->assertSame($payload['svg'], $svg);
    }

    public function test_svg_for_token_rejects_unknown_token(): void
    {
        $session = new Store('test', new ArraySessionHandler(120));
        $request = Request::create('/login', 'GET');
        $request->setLaravelSession($session);

        $service = new LoginCaptchaService;
        $service->issue($request);

        $this->assertNull($service->svgForToken($request, str_repeat('x', 40)));
    }
}
