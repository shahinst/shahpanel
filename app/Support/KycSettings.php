<?php

namespace App\Support;

use App\Enums\KycProvider;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class KycSettings
{
    public const KEY_ENABLED = 'kyc_enabled';

    public const KEY_PROVIDER = 'kyc_provider';

    public const KEY_API_IR_KEY = 'kyc_api_ir_api_key_enc';

    public const KEY_CREDIT_TOMAN = 'kyc_api_credit_toman';

    public const KEY_CREDIT_CHECKED_AT = 'kyc_api_credit_checked_at';

    public const KEY_CREDIT_SOURCE = 'kyc_api_credit_source';

    public const KEY_LAST_TEST_AT = 'kyc_api_last_test_at';

    public const KEY_LAST_TEST_OK = 'kyc_api_last_test_ok';

    public static function enabled(): bool
    {
        return Setting::getValue(self::KEY_ENABLED, '0') === '1';
    }

    public static function setEnabled(bool $enabled): void
    {
        Setting::setValue(self::KEY_ENABLED, $enabled ? '1' : '0');
    }

    public static function provider(): KycProvider
    {
        return KycProvider::tryFrom((string) Setting::getValue(self::KEY_PROVIDER, KycProvider::ApiIr->value))
            ?? KycProvider::ApiIr;
    }

    public static function setProvider(KycProvider|string $provider): void
    {
        $value = $provider instanceof KycProvider ? $provider->value : (string) $provider;
        Setting::setValue(self::KEY_PROVIDER, KycProvider::tryFrom($value)?->value ?? KycProvider::ApiIr->value);
    }

    public static function hasApiKey(): bool
    {
        return self::apiKey() !== null;
    }

    public static function apiKey(): ?string
    {
        $stored = Setting::getValue(self::KEY_API_IR_KEY);

        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString($stored);
        } catch (DecryptException) {
            $plain = $stored;
        }

        $normalized = self::normalizeApiKey((string) $plain);

        return $normalized !== '' ? $normalized : null;
    }

    public static function setApiKey(?string $plain): void
    {
        if ($plain === null || trim($plain) === '') {
            Setting::setValue(self::KEY_API_IR_KEY, null);

            return;
        }

        Setting::setValue(self::KEY_API_IR_KEY, Crypt::encryptString(self::normalizeApiKey($plain)));
    }

    public static function normalizeApiKey(string $plain): string
    {
        $value = trim($plain);
        // Users often paste "Bearer xxx"; Laravel Http::withToken() already adds Bearer.
        $value = (string) preg_replace('/^Bearer\s+/i', '', $value);

        return trim($value);
    }

    public static function isReady(): bool
    {
        return self::enabled() && self::hasApiKey();
    }

    public static function creditToman(): ?string
    {
        $value = Setting::getValue(self::KEY_CREDIT_TOMAN);

        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 0, '.', '');
    }

    public static function setCreditToman(int|float|string|null $amount): void
    {
        if ($amount === null || $amount === '') {
            Setting::setValue(self::KEY_CREDIT_TOMAN, null);
            Setting::setValue(self::KEY_CREDIT_CHECKED_AT, null);

            return;
        }

        $normalized = western_digits((string) $amount);
        $normalized = str_replace([',', '،', ' '], '', $normalized);

        Setting::setValue(self::KEY_CREDIT_TOMAN, number_format((float) $normalized, 0, '.', ''));
        Setting::setValue(self::KEY_CREDIT_CHECKED_AT, now()->toIso8601String());
        Setting::setValue(self::KEY_CREDIT_SOURCE, 'manual');
    }

    public static function setCreditTomanFromApi(int|float|string|null $amount): void
    {
        if ($amount === null || $amount === '') {
            return;
        }

        $normalized = western_digits((string) $amount);
        $normalized = str_replace([',', '،', ' '], '', $normalized);

        Setting::setValue(self::KEY_CREDIT_TOMAN, number_format((float) $normalized, 0, '.', ''));
        Setting::setValue(self::KEY_CREDIT_CHECKED_AT, now()->toIso8601String());
        Setting::setValue(self::KEY_CREDIT_SOURCE, 'api');
    }

    public static function creditSource(): ?string
    {
        $value = Setting::getValue(self::KEY_CREDIT_SOURCE);

        return ($value !== null && $value !== '') ? $value : null;
    }

    public static function creditCheckedAt(): ?string
    {
        $value = Setting::getValue(self::KEY_CREDIT_CHECKED_AT);

        return ($value !== null && $value !== '') ? $value : null;
    }

    public static function markTestResult(bool $ok): void
    {
        Setting::setValue(self::KEY_LAST_TEST_OK, $ok ? '1' : '0');
        Setting::setValue(self::KEY_LAST_TEST_AT, now()->toIso8601String());
    }

    public static function lastTestOk(): ?bool
    {
        $value = Setting::getValue(self::KEY_LAST_TEST_OK);
        if ($value === null || $value === '') {
            return null;
        }

        return $value === '1';
    }

    public static function lastTestAt(): ?string
    {
        $value = Setting::getValue(self::KEY_LAST_TEST_AT);

        return ($value !== null && $value !== '') ? $value : null;
    }
}
