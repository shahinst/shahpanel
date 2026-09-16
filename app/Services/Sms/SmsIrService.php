<?php

namespace App\Services\Sms;

use App\Models\Account;
use App\Support\SmsSettings;
use Illuminate\Support\Facades\URL;

final class SmsIrService
{
    public function client(): SmsIrClient
    {
        $key = SmsSettings::smsIrApiKey();

        if ($key === null || $key === '') {
            throw new SmsIrApiException(__('services.smsir_api_key_missing'));
        }

        return new SmsIrClient($key);
    }

    public function credit(): float
    {
        $data = $this->client()->getCredit()['data'];

        return is_numeric($data) ? (float) $data : 0.0;
    }

    /**
     * @return list<int|string>
     */
    public function lines(): array
    {
        return $this->client()->getLines();
    }

    /**
     * @return list<array{id: int, title: string, text: ?string}>
     */
    public function verifyTemplates(): array
    {
        return $this->client()->getVerifyTemplates();
    }

    /**
     * @return array{messageId: int|null, cost: float|null}
     */
    public function sendAccountLoginInfo(string $mobile, Account $account): array
    {
        $templateId = SmsSettings::smsIrVerifyTemplateId();

        if ($templateId === null) {
            throw new SmsIrApiException(__('services.smsir_template_missing'));
        }

        return $this->client()->sendVerify(
            self::normalizeMobile($mobile),
            $templateId,
            [
                [
                    'name' => SmsSettings::verifyLoginParameterName(),
                    'value' => self::accountPortalUrl($account),
                ],
            ],
        );
    }

    /**
     * @return array{messageId: int|null, cost: float|null}
     */
    public function sendVerifyTest(string $mobile, string $loginUrl): array
    {
        $templateId = SmsSettings::smsIrVerifyTemplateId();

        if ($templateId === null) {
            throw new SmsIrApiException(__('services.smsir_template_missing'));
        }

        return $this->client()->sendVerify(
            self::normalizeMobile($mobile),
            $templateId,
            [
                [
                    'name' => SmsSettings::verifyLoginParameterName(),
                    'value' => $loginUrl,
                ],
            ],
        );
    }

    /**
     * @return array{packId: ?string, messageIds: list<int|null>, cost: float|null}
     */
    public function sendTest(string $mobile, string $messageText): array
    {
        $line = SmsSettings::smsIrLineNumber();

        if ($line === null || $line === '') {
            throw new SmsIrApiException(__('services.smsir_line_missing'));
        }

        return $this->client()->sendBulk(
            $line,
            $messageText,
            [self::normalizeMobile($mobile)],
        );
    }

    public static function accountPortalUrl(Account $account): string
    {
        return URL::to(route('portal.show', $account->portal_token, absolute: false));
    }

    public static function previewAccountMessage(Account $account): string
    {
        $message = SmsSettings::accountLoginMessage();
        $loginUrl = self::accountPortalUrl($account);
        $param = SmsSettings::verifyLoginParameterName();

        return str_replace(
            ['#'.$param.'#', '#LOGIN#'],
            [$loginUrl, $loginUrl],
            $message,
        );
    }

    /**
     * فقط موبایل ایران معتبر است (قالب نهایی 09xxxxxxxxx). بدون این بررسی،
     * یک شماره اشتباه به sms.ir می‌رود و در «ارسال اطلاعات اکانت» سهمیه
     * یک‌بار ارسال هر اکانت را می‌سوزاند.
     */
    public static function isValidIranMobile(string $mobile): bool
    {
        return preg_match('/^09\d{9}$/', self::normalizeMobile($mobile)) === 1;
    }

    public static function normalizeMobile(string $mobile): string
    {
        // ارقام فارسی/عربی را اول به لاتین تبدیل می‌کنیم؛ کاربر پنل شماره را
        // معمولاً با ارقام فارسی وارد یا کپی می‌کند و \D در حالت عادی همهٔ
        // آن‌ها را حذف می‌کرد و رشتهٔ خالی به sms.ir می‌رفت.
        $mobile = str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $mobile,
        );

        $digits = preg_replace('/\D+/', '', $mobile) ?? '';

        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '98') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            $digits = '0'.$digits;
        }

        return $digits;
    }
}
