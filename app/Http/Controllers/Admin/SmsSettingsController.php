<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Sms\SmsIrApiException;
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
        $hasApiKey = SmsSettings::hasSmsIrApiKey();
        $lines = [];
        $templates = [];
        $credit = null;
        $panelError = null;

        if ($hasApiKey) {
            try {
                $lines = Cache::remember('sms_ir.lines', now()->addMinutes(10), fn () => $smsIrService->lines());
                $templates = Cache::remember('sms_ir.verify_templates', now()->addMinutes(10), fn () => $smsIrService->verifyTemplates());
                $credit = Cache::remember('sms_ir.credit', now()->addMinutes(2), fn () => $smsIrService->credit());
            } catch (SmsIrApiException $exception) {
                $panelError = $exception->getMessage();
            } catch (\Throwable $exception) {
                report($exception);
                $panelError = $exception->getMessage();
            }
        }

        return view('admin.sms.index', [
            'provider' => SmsSettings::provider(),
            'hasApiKey' => $hasApiKey,
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
            'sms_provider' => ['required', 'in:'.SmsSettings::PROVIDER_SMS_IR],
            'sms_ir_api_key' => ['nullable', 'string', 'max:500'],
            'sms_ir_line_number' => ['nullable', 'string', 'max:32'],
            'sms_ir_verify_template_id' => ['nullable', 'integer', 'min:1'],
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
                ->withInput($request->except('sms_ir_api_key'))
                ->withErrors([
                    'sms_account_login_message' => __('sms.parameter_missing_in_message', ['parameter' => $parameter]),
                ]);
        }

        SmsSettings::setProvider($validated['sms_provider']);

        $newKey = trim((string) ($validated['sms_ir_api_key'] ?? ''));
        if ($newKey !== '') {
            SmsSettings::setSmsIrApiKey($newKey);
        }

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

    public function sendTest(Request $request, SmsIrService $smsIrService): RedirectResponse
    {
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
        } catch (SmsIrApiException $exception) {
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
}
