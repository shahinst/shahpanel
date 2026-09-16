<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class SmsSettings
{
    public const PROVIDER_SMS_IR = 'sms_ir';

    public const KEY_PROVIDER = 'sms_provider';

    public const KEY_SMS_IR_API = 'sms_ir_api_key_enc';

    public const KEY_SMS_IR_LINE = 'sms_ir_line_number';

    public const KEY_SMS_IR_VERIFY_TEMPLATE_ID = 'sms_ir_verify_template_id';

    public const KEY_SMS_ACCOUNT_MESSAGE = 'sms_account_login_message';

    public const KEY_SMS_VERIFY_PARAMETER = 'sms_verify_login_parameter';

    public const KEY_ACCOUNT_LOGIN_SMS_ENABLED = 'sms_account_login_enabled';

    public static function provider(): string
    {
        return Setting::getValue(self::KEY_PROVIDER, self::PROVIDER_SMS_IR) ?? self::PROVIDER_SMS_IR;
    }

    public static function setProvider(string $provider): void
    {
        Setting::setValue(self::KEY_PROVIDER, $provider);
    }

    public static function hasSmsIrApiKey(): bool
    {
        return self::smsIrApiKey() !== null;
    }

    public static function smsIrApiKey(): ?string
    {
        $stored = Setting::getValue(self::KEY_SMS_IR_API);

        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return $stored;
        }
    }

    public static function setSmsIrApiKey(?string $plain): void
    {
        if ($plain === null || trim($plain) === '') {
            Setting::setValue(self::KEY_SMS_IR_API, null);

            return;
        }

        Setting::setValue(self::KEY_SMS_IR_API, Crypt::encryptString(trim($plain)));
    }

    public static function smsIrLineNumber(): ?string
    {
        $value = Setting::getValue(self::KEY_SMS_IR_LINE);

        return ($value !== null && $value !== '') ? $value : null;
    }

    public static function setSmsIrLineNumber(?string $lineNumber): void
    {
        $line = trim((string) $lineNumber);
        Setting::setValue(self::KEY_SMS_IR_LINE, $line !== '' ? $line : null);
    }

    public static function smsIrVerifyTemplateId(): ?int
    {
        $value = Setting::getValue(self::KEY_SMS_IR_VERIFY_TEMPLATE_ID);

        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public static function setSmsIrVerifyTemplateId(?int $templateId): void
    {
        Setting::setValue(
            self::KEY_SMS_IR_VERIFY_TEMPLATE_ID,
            $templateId !== null && $templateId > 0 ? (string) $templateId : null,
        );
    }

    public static function accountLoginMessage(): string
    {
        $stored = trim((string) Setting::getValue(self::KEY_SMS_ACCOUNT_MESSAGE, ''));

        if ($stored !== '') {
            return $stored;
        }

        return (string) config('sms.sms_ir.default_account_message', "آدرس ورود شما :\n#LOGIN#");
    }

    public static function setAccountLoginMessage(?string $message): void
    {
        $text = trim((string) $message);
        Setting::setValue(self::KEY_SMS_ACCOUNT_MESSAGE, $text !== '' ? $text : null);
    }

    public static function verifyLoginParameterName(): string
    {
        $stored = trim((string) Setting::getValue(self::KEY_SMS_VERIFY_PARAMETER, ''));

        if ($stored !== '') {
            return self::normalizeParameterName($stored);
        }

        return self::normalizeParameterName((string) config('sms.sms_ir.default_verify_parameter', 'LOGIN'));
    }

    public static function setVerifyLoginParameterName(?string $name): void
    {
        $normalized = self::normalizeParameterName(trim((string) $name));
        Setting::setValue(
            self::KEY_SMS_VERIFY_PARAMETER,
            $normalized !== '' ? $normalized : null,
        );
    }

    public static function isAccountLoginSmsEnabled(): bool
    {
        return Setting::getValue(self::KEY_ACCOUNT_LOGIN_SMS_ENABLED, '0') === '1';
    }

    public static function setAccountLoginSmsEnabled(bool $enabled): void
    {
        Setting::setValue(self::KEY_ACCOUNT_LOGIN_SMS_ENABLED, $enabled ? '1' : '0');
    }

    public static function isAccountLoginSmsReady(): bool
    {
        return self::isAccountLoginSmsEnabled()
            && self::hasSmsIrApiKey()
            && self::smsIrVerifyTemplateId() !== null;
    }

    public static function normalizeParameterName(string $name): string
    {
        $name = trim($name);
        $name = trim($name, '#');

        return strtoupper($name);
    }

    /**
     * پارامترهای #NAME# موجود در متن پیامک. اگر متنی داده نشود، متن ذخیره‌شده
     * خوانده می‌شود (برای اعتبارسنجی قبل از ذخیره، متن ارسالی فرم پاس داده می‌شود).
     *
     * @return list<string>
     */
    public static function placeholdersInMessage(?string $message = null): array
    {
        $message ??= self::accountLoginMessage();
        preg_match_all('/#([A-Za-z0-9_]+)#/', $message, $matches);

        return array_values(array_unique(array_map(
            static fn (string $key): string => strtoupper($key),
            $matches[1] ?? [],
        )));
    }
}
