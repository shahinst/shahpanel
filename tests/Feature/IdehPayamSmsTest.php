<?php

namespace Tests\Feature;

use App\Services\Sms\SmsApiException;
use App\Services\Sms\SmsGateway;
use App\Support\SmsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class IdehPayamSmsTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    protected function configure(): void
    {
        SmsSettings::setProvider(SmsSettings::PROVIDER_IDEHPAYAM);
        SmsSettings::setIdehPayamUsername('user1');
        SmsSettings::setIdehPayamPassword('secret');
        SmsSettings::setIdehPayamFrom('30001234');
        SmsSettings::setIdehPayamType(1);
    }

    public function test_send_uses_documented_headers_and_body(): void
    {
        $this->configure();
        Http::fake(['185.112.33.62/*' => Http::response(['status' => true, 'data' => [987]])]);

        $result = app(SmsGateway::class)->sendText(['۰۹۱۲۱۲۳۴۵۶۷', '+989351112233'], 'سلام');

        $this->assertSame('987', $result['messageId']);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://185.112.33.62/api/v1/rest/sms/send'
                && $request->hasHeader('username', 'user1')
                && $request->hasHeader('password', 'secret')
                && $request['from'] === '30001234'
                && $request['recipients'] === ['09121234567', '09351112233']
                && $request['message'] === 'سلام'
                && $request['type'] === 1;
        });
    }

    public function test_refusal_in_body_is_an_error(): void
    {
        $this->configure();
        Http::fake(['*' => Http::response(['status' => false, 'message' => 'credit is low'])]);

        $this->expectException(SmsApiException::class);
        $this->expectExceptionMessage('credit is low');

        app(SmsGateway::class)->sendText(['09121234567'], 'x');
    }

    public function test_admin_saves_idehpayam_settings_without_echoing_the_password(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->put(route('admin.sms.update'), [
            'sms_provider' => 'idehpayam',
            'idehpayam_username' => 'u',
            'idehpayam_password' => 'p@ss',
            'idehpayam_from' => '3000999',
            'idehpayam_type' => 0,
            'sms_account_login_message' => "ورود:\n#LOGIN#",
            'sms_verify_login_parameter' => 'LOGIN',
            'sms_account_login_enabled' => 1,
        ])->assertRedirect(route('admin.sms.index'));

        $this->assertSame('p@ss', SmsSettings::idehPayamPassword());
        $this->assertTrue(SmsSettings::isAccountLoginSmsReady());

        $this->actingAs($admin)->get(route('admin.sms.index'))->assertOk()->assertDontSee('p@ss');
    }
}
