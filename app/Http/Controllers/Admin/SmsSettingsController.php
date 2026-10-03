<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Sms\SmsApiException;
use App\Services\Sms\SmsGateway;
use App\Services\Sms\SmsIrService;
use App\Support\SmsSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SmsSettingsController extends Controller
{
    /**
     * پاسخ‌های sms.ir کش می‌شوند تا هر بار باز شدن این صفحه تا پنج درخواست
     * HTTP پشت‌سرهم نزند (فهرست قالب‌ها به‌تنهایی سه مسیر را امتحان می‌کند و
     * اگر sms.ir کند باشد، صفحه تا دقیقه‌ها بلوکه می‌ماند). با ذخیرهٔ تنظیمات
     * کش پاک می‌شود تا خطوط و اعتبار دوباره خوانده شوند.
     */
    private const CACHE_KEYS = ['sms_ir.lines', 'sms_ir.verify_templates', 'sms_ir.credit'];

    public function index(SmsIrService $smsIrService): View
    {
        $provider = SmsSettings::provider();
        $hasApiKey = SmsSettings::hasSmsIrApiKey();
        $lines = [];
        $templates = [];
        $credit = null;
        $panelError = null;

        if ($hasApiKey && $provider === SmsSettings::PROVIDER_SMS_IR) {
            try {
                $lines = Cache::remember('sms_ir.lines', now()->addMinutes(10), fn () => $smsIrService->lines());
                $templates = Cache::remember('sms_ir.verify_templates', now()->addMinutes(10), fn () => $smsIrService->verifyTemplates());
                $credit = Cache::remember('sms_ir.credit', now()->addMinutes(2), fn () => $smsIrService->credit());
            } catch (SmsApiException $exception) {
                $panelError = $exception->getMessage();
            } catch (\Throwable $exception) {
                report($exception);
                $panelError = $exception->getMessage();
            }
        }

        return view('admin.sms.index', [
            'provider' => $provider,
            'hasApiKey' => $hasApiKey,
            'providerConfigured' => SmsSettings::isProviderConfigured(),
            'idehPayam' => [
                'username' => SmsSettings::idehPayamUsername(),
                'has_password' => SmsSettings::idehPayamPassword() !== null,
                'from' => SmsSettings::idehPayamFrom(),
                'type' => SmsSettings::idehPayamType(),
                'base_url' => SmsSettings::idehPayamBaseUrl(),
                'default_base_url' => (string) config('sms.idehpayam.base_url'),
            ],
            'lineNumber' => SmsSettings::smsIrLineNumber(),
            'verifyTemplateId' => SmsSettings::smsIrVerifyTemplateId(),
            'accountLoginMessage' => SmsSettings::accountLoginMessage(),
            'verifyParameterName' => SmsSettings::verifyLoginParameterName(),
            'accountLoginSmsEnabled' => SmsSettings::isAccountLoginSmsEnabled(),
            'lines' => $lines,
            'templates' => $templates,
            'credit' => $credit,
            'panelError' => $panelError,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sms_provider' => ['required', 'in:'.implode(',', SmsSettings::PROVIDERS)],
            'sms_ir_api_key' => ['nullable', 'string', 'max:500'],
            'sms_ir_line_number' => ['nullable', 'string', 'max:32'],
            'sms_ir_verify_template_id' => ['nullable', 'integer', 'min:1'],
            'idehpayam_username' => ['nullable', 'string', 'max:100'],
            'idehpayam_password' => ['nullable', 'string', 'max:200'],
            'idehpayam_from' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9]+$/'],
            'idehpayam_type' => ['nullable', 'integer', 'in:'.implode(',', (array) config('sms.idehpayam.types', [0, 1]))],
            'idehpayam_base_url' => ['nullable', 'url:http,https', 'max:255'],
            'sms_account_login_message' => ['required', 'string', 'max:900'],
            'sms_verify_login_parameter' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/'],
        ]);

        $parameter = SmsSettings::normalizeParameterName($validated['sms_verify_login_parameter']);

        // نام پارامتر باید در متن پیامک هم به شکل #NAME# آمده باشد؛ در غیر این
        // صورت پیش‌نمایش پنل لینک پورتال را نشان نمی‌دهد و قالب Verify در sms.ir
        // هم جای دیگری برای جایگزینی ندارد.
        if (! in_array($parameter, SmsSettings::placeholdersInMessage($validated['sms_account_login_message']), true)) {
            // کلید API را در سشن فلش نمی‌کنیم؛ فیلد رمزی است و نباید در old input بماند.
            return back()
                ->withInput($request->except('sms_ir_api_key', 'idehpayam_password'))
                ->withErrors([
                    'sms_account_login_message' => __('sms.parameter_missing_in_message', ['parameter' => $parameter]),
                ]);
        }

        SmsSettings::setProvider($validated['sms_provider']);

        $newKey = trim((string) ($validated['sms_ir_api_key'] ?? ''));
        if ($newKey !== '') {
            SmsSettings::setSmsIrApiKey($newKey);
        }

        SmsSettings::setIdehPayamUsername($validated['idehpayam_username'] ?? null);
        if (trim((string) ($validated['idehpayam_password'] ?? '')) !== '') {
            SmsSettings::setIdehPayamPassword(trim((string) $validated['idehpayam_password']));
        }
        SmsSettings::setIdehPayamFrom($validated['idehpayam_from'] ?? null);
        SmsSettings::setIdehPayamType((int) ($validated['idehpayam_type'] ?? 0));
        SmsSettings::setIdehPayamBaseUrl($validated['idehpayam_base_url'] ?? null);

        SmsSettings::setSmsIrLineNumber($validated['sms_ir_line_number'] ?? null);
        SmsSettings::setSmsIrVerifyTemplateId(
            isset($validated['sms_ir_verify_template_id']) ? (int) $validated['sms_ir_verify_template_id'] : null,
        );
        SmsSettings::setAccountLoginMessage($validated['sms_account_login_message']);
        SmsSettings::setVerifyLoginParameterName($validated['sms_verify_login_parameter']);
        SmsSettings::setAccountLoginSmsEnabled($request->boolean('sms_account_login_enabled'));

        foreach (self::CACHE_KEYS as $cacheKey) {
            Cache::forget($cacheKey);
        }

        return redirect()
            ->route('admin.sms.index')
            ->with('success', __('sms.settings_saved'));
    }

    public function sendTest(Request $request, SmsIrService $smsIrService, SmsGateway $gateway): RedirectResponse
    {
        if (SmsSettings::provider() === SmsSettings::PROVIDER_IDEHPAYAM) {
            return $this->sendIdehPayamTest($request, $gateway);
        }

        if (! SmsSettings::hasSmsIrApiKey()) {
            return redirect()
                ->route('admin.sms.index')
                ->with('error', __('sms.api_key_required'));
        }

        $validated = $request->validate([
            'test_mobile' => ['required', 'string', 'max:20'],
            'test_login_url' => ['nullable', 'url', 'max:500'],
        ]);

        if (! SmsIrService::isValidIranMobile($validated['test_mobile'])) {
            return redirect()
                ->route('admin.sms.index')
                ->with('error', __('sms.invalid_mobile'));
        }

        $loginUrl = $validated['test_login_url'] ?? url('/portal/example-token');

        try {
            if (SmsSettings::isAccountLoginSmsReady()) {
                $result = $smsIrService->sendVerifyTest($validated['test_mobile'], $loginUrl);
                $messageId = $result['messageId'];
                $cost = $result['cost'];
            } else {
                $validatedBulk = $request->validate([
                    'test_message' => ['required', 'string', 'max:900'],
                ]);
                $result = $smsIrService->sendTest($validated['test_mobile'], $validatedBulk['test_message']);
                $messageId = $result['messageIds'][0] ?? null;
                $cost = $result['cost'];
            }

            $flash = __('sms.test_sent', [
                'message_id' => $messageId !== null ? (string) $messageId : '—',
                'cost' => $cost !== null ? persian_digits(number_format($cost, 2)) : '—',
            ]);

            if ($messageId === 0 || $messageId === '0') {
                $flash .= ' '.__('sms.test_blacklist');
            }

            return redirect()
                ->route('admin.sms.index')
                ->with('success', $flash);
        } catch (SmsApiException $exception) {
            return redirect()
                ->route('admin.sms.index')
                ->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.sms.index')
                ->with('error', $exception->getMessage());
        }
    }

    /**
     * IdehPayam has no Verify templates: the test sends either the typed text
     * or, when account SMS is on, the real login message, to one or more
     * numbers (comma or new line separated).
     */
    protected function sendIdehPayamTest(Request $request, SmsGateway $gateway): RedirectResponse
    {
        $validated = $request->validate([
            'test_mobile' => ['required', 'string', 'max:500'],
            'test_message' => ['nullable', 'string', 'max:900'],
        ]);

        $mobiles = array_values(array_filter(array_map('trim', preg_split('/[\s,،]+/u', $validated['test_mobile']) ?: [])));

        if ($mobiles === [] || count($mobiles) > 20) {
            return redirect()->route('admin.sms.index')->with('error', __('sms.invalid_mobile'));
        }

        foreach ($mobiles as $mobile) {
            if (! SmsIrService::isValidIranMobile($mobile)) {
                return redirect()->route('admin.sms.index')->with('error', __('sms.invalid_mobile').' ('.$mobile.')');
            }
        }

        $message = trim((string) ($validated['test_message'] ?? ''));

        if ($message === '') {
            $message = str_replace(['#'.SmsSettings::verifyLoginParameterName().'#', '#LOGIN#'], url('/portal/example'), SmsSettings::accountLoginMessage());
        }

        try {
            $result = $gateway->sendText($mobiles, $message);
        } catch (SmsApiException $exception) {
            return redirect()->route('admin.sms.index')->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('admin.sms.index')->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.sms.index')->with('success', __('sms.test_sent', [
            'message_id' => $result['messageId'] !== null ? persian_digits((string) $result['messageId']) : '—',
            'cost' => '—',
        ]));
    }
}
