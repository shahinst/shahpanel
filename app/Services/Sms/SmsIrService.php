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
            throw new SmsIrApiException('کلید API پیامک (sms.ir) ذخیره نشده است.');
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
            throw new SmsIrApiException('شناسه قالب پیامک (Verify) در تنظیمات ذخیره نشده است.');
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
            throw new SmsIrApiException('شناسه قالب پیامک (Verify) در تنظیمات ذخیره نشده است.');
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
            throw new SmsIrApiException('شماره خط ارسال انتخاب نشده است.');
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

    public static function normalizeMobile(string $mobile): string
    {
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
