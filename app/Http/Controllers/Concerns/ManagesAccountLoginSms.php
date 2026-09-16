<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\UserRole;
use App\Models\Account;
use App\Services\PortalLinkService;
use App\Services\Sms\SmsIrApiException;
use App\Services\Sms\SmsIrService;
use App\Support\SmsSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

trait ManagesAccountLoginSms
{
    public function showSendLoginInfo(Account $account): View|RedirectResponse
    {
        try {
            $this->authorize('view', $account);

            $user = auth()->user();
            if (! $user || ! $this->viewerMaySendLoginSms($user->role)) {
                abort(403);
            }

            if ($account->isRefunded() || ! filled($account->portal_token)) {
                abort(404);
            }

            if (! SmsSettings::isAccountLoginSmsEnabled()) {
                abort(404);
            }

            $account->loadMissing('clientUser');

            $previewMessage = $this->safePreviewAccountMessage($account);

            return view('shared.accounts.send-login-info', [
                'account' => $account,
                'prefix' => $this->accountRoutePrefix(),
                'smsReady' => $this->isAccountLoginSmsConfigured(),
                'defaultMobile' => $account->clientUser?->phone,
                'previewMessage' => $previewMessage,
                'portalUrl' => null,
                'portalLinkTtlMinutes' => app(PortalLinkService::class)->ttlMinutes(),
            ]);
        } catch (\Throwable $exception) {
            if ($exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
                throw $exception;
            }

            if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                throw $exception;
            }

            report($exception);

            return redirect()
                ->back()
                ->with('error', __('accounts.send_login_info_page_error', [
                    'message' => $exception->getMessage(),
                ]));
        }
    }

    public function sendLoginInfo(Request $request, Account $account, SmsIrService $smsIrService): RedirectResponse
    {
        $this->authorize('sendLoginInfo', $account);

        if ($account->loginSmsSent()) {
            return redirect()
                ->route($this->accountRoutePrefix().'.accounts.'.$account->service_type->accountCategory()->value)
                ->with('error', __('accounts.send_login_info_already_sent'));
        }

        if (! SmsSettings::isAccountLoginSmsEnabled()) {
            return redirect()
                ->route($this->accountRoutePrefix().'.accounts.'.$account->service_type->accountCategory()->value)
                ->with('error', __('sms.account_login_disabled'));
        }

        if (! $this->isAccountLoginSmsConfigured()) {
            return redirect()
                ->route($this->accountRoutePrefix().'.accounts.send-login-info-form', $account)
                ->with('error', __('sms.account_sms_not_configured'));
        }

        $validated = $request->validate([
            'mobile' => ['required', 'string', 'max:20'],
        ]);

        $formRedirect = fn (string $message): RedirectResponse => redirect()
            ->route($this->accountRoutePrefix().'.accounts.send-login-info-form', $account)
            ->withInput()
            ->with('error', $message);

        if (! SmsIrService::isValidIranMobile($validated['mobile'])) {
            return $formRedirect(__('sms.invalid_mobile'));
        }

        $redirect = redirect()
            ->route($this->accountRoutePrefix().'.accounts.'.$account->service_type->accountCategory()->value);

        // قفل اتمیک: بدون آن چند درخواست همزمان روی یک اکانت همگی از شرط
        // loginSmsSent() بالا عبور می‌کنند (چون علامت‌گذاری بعد از تماس HTTP
        // انجام می‌شود) و اعتبار پیامک چند بار مصرف می‌شود.
        $lock = Cache::lock('sms:account-login-info:'.$account->id, 120);

        if (! $lock->get()) {
            return $redirect->with('error', __('sms.account_login_in_progress'));
        }

        try {
            $account->refresh();

            if ($account->loginSmsSent()) {
                return $redirect->with('error', __('accounts.send_login_info_already_sent'));
            }

            app(PortalLinkService::class)->issue($account);
            $account->refresh();
            $result = $smsIrService->sendAccountLoginInfo($validated['mobile'], $account);
            $messageId = $result['messageId'];
            $cost = $result['cost'];

            // messageId=0 یعنی شماره در لیست سیاه sms.ir است و پیامکی تحویل نشده؛
            // پس اکانت را «ارسال‌شده» علامت نمی‌زنیم تا امکان ارسال مجدد بماند.
            if ($messageId === 0) {
                return $formRedirect(__('sms.test_blacklist'));
            }

            $account->markLoginSmsSent();

            return $redirect->with('success', __('sms.account_login_sent', [
                'message_id' => $messageId !== null ? (string) $messageId : '—',
                'cost' => $cost !== null ? persian_digits(number_format($cost, 2)) : '—',
            ]));
        } catch (SmsIrApiException $exception) {
            return $formRedirect($exception->getMessage());
        } catch (\Throwable $exception) {
            // پیام خام استثنا را به نماینده/فروشنده نشان نمی‌دهیم؛ ممکن است
            // جزئیات داخلی (کوئری، مسیر فایل) داشته باشد. فقط لاگ می‌شود.
            report($exception);

            return $formRedirect(__('sms.account_login_failed'));
        } finally {
            $lock->release();
        }
    }

    protected function viewerMaySendLoginSms(mixed $role): bool
    {
        if ($role instanceof UserRole) {
            return in_array($role, [UserRole::Admin, UserRole::Agent, UserRole::Seller], true);
        }

        return in_array((string) $role, ['admin', 'agent', 'seller'], true);
    }

    protected function isAccountLoginSmsConfigured(): bool
    {
        try {
            return SmsSettings::isAccountLoginSmsReady();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function safeAccountPortalUrl(Account $account): string
    {
        try {
            if (Route::has('portal.show') && filled($account->portal_token)) {
                return SmsIrService::accountPortalUrl($account);
            }
        } catch (RouteNotFoundException) {
            // fall through
        } catch (\Throwable $exception) {
            report($exception);
        }

        return url('/portal/'.(string) $account->portal_token);
    }

    protected function safePreviewAccountMessage(Account $account): string
    {
        try {
            $message = SmsSettings::accountLoginMessage();
            $param = SmsSettings::verifyLoginParameterName();
            $placeholder = __('accounts.portal_link_preview_placeholder', [
                'minutes' => persian_digits(app(PortalLinkService::class)->ttlMinutes()),
            ]);

            return str_replace(
                ['#'.$param.'#', '#LOGIN#'],
                [$placeholder, $placeholder],
                $message,
            );
        } catch (\Throwable $exception) {
            report($exception);

            return __('accounts.portal_link_preview_placeholder', [
                'minutes' => persian_digits(app(PortalLinkService::class)->ttlMinutes()),
            ]);
        }
    }
}
