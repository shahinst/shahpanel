<?php

namespace App\Support;

use App\Models\Server;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Bot credentials for delivering server backups to Telegram.
 *
 * Both secrets live in the shared `settings` key/value table, so an `encrypted`
 * cast (as `servers.api_token_enc` uses) is not available — a single `value`
 * column cannot be cast per key. They are therefore encrypted explicitly with
 * Crypt::encryptString, exactly like SmsSettings and KycSettings do.
 */
final class ServerBackupTelegramSettings
{
    public const KEY_ENABLED = 'server_backup_telegram_enabled';

    public const KEY_BOT_TOKEN = 'server_backup_telegram_bot_token_enc';

    public const KEY_CHAT_ID = 'server_backup_telegram_chat_id_enc';

    /**
     * زمان‌بندی بک‌آپ دیتابیسِ خودِ پنل: کلید و ساعت‌هایش از سرورها جداست، چون
     * ادمین باید بتواند یکی را روشن و دیگری را خاموش بگذارد. به مایگریشن نیازی
     * نیست؛ مثل بقیهٔ تنظیمات فقط دو ردیف در جدول settings است.
     */
    public const KEY_DATABASE_ENABLED = 'server_backup_telegram_database_enabled';

    public const KEY_DATABASE_TIMES = 'server_backup_telegram_database_times';

    public static function isEnabled(): bool
    {
        return Setting::getValue(self::KEY_ENABLED, '0') === '1';
    }

    public static function setEnabled(bool $enabled): void
    {
        Setting::setValue(self::KEY_ENABLED, $enabled ? '1' : '0');
    }

    public static function botToken(): ?string
    {
        return self::readSecret(self::KEY_BOT_TOKEN);
    }

    public static function setBotToken(?string $plain): void
    {
        self::writeSecret(self::KEY_BOT_TOKEN, $plain);
    }

    public static function chatId(): ?string
    {
        return self::readSecret(self::KEY_CHAT_ID);
    }

    public static function setChatId(?string $plain): void
    {
        self::writeSecret(self::KEY_CHAT_ID, $plain);
    }

    /** Credentials present — says nothing about whether they are valid. */
    public static function isConfigured(): bool
    {
        return self::botToken() !== null && self::chatId() !== null;
    }

    /** Configured *and* switched on: the condition the scheduler checks. */
    public static function isReady(): bool
    {
        return self::isEnabled() && self::isConfigured();
    }

    public static function isDatabaseEnabled(): bool
    {
        return Setting::getValue(self::KEY_DATABASE_ENABLED, '0') === '1';
    }

    public static function setDatabaseEnabled(bool $enabled): void
    {
        Setting::setValue(self::KEY_DATABASE_ENABLED, $enabled ? '1' : '0');
    }

    /**
     * ساعت‌ها با همان پارسر سرورها خوانده می‌شوند (Server::normalizeBackupTimes)
     * تا «۰۳:۳۰، ۱۵:۰۰» با ارقام و ویرگول فارسی هم درست بفهمد و خروجی همیشه
     * فهرست مرتبِ «HH:MM» باشد؛ زمان‌بند فقط رشته مقایسه می‌کند.
     *
     * @return list<string>
     */
    public static function databaseTimes(): array
    {
        return Server::normalizeBackupTimes(Setting::getValue(self::KEY_DATABASE_TIMES, ''));
    }

    /**
     * @return list<string> همان ساعت‌های پذیرفته‌شده، تا فراخوان بداند چه ذخیره شد
     */
    public static function setDatabaseTimes(mixed $value): array
    {
        $times = Server::normalizeBackupTimes($value);

        Setting::setValue(self::KEY_DATABASE_TIMES, $times === [] ? null : implode(',', $times));

        return $times;
    }

    /**
     * Credentials present, Telegram delivery on, the database schedule itself on
     * and at least one valid time — the exact condition the scheduler checks. A
     * schedule with no valid time would never fire, so it does not count as
     * ready.
     */
    public static function isDatabaseReady(): bool
    {
        return self::isReady() && self::isDatabaseEnabled() && self::databaseTimes() !== [];
    }

    /**
     * Only ever this — the raw token must never reach a view, a log or a URL
     * that could be logged. Telegram tokens look like `<bot_id>:<secret>`, and
     * the numeric bot id alone is enough for the admin to recognise the bot.
     */
    public static function maskedBotToken(): ?string
    {
        $token = self::botToken();

        if ($token === null) {
            return null;
        }

        $botId = (string) strstr($token, ':', true);

        return ($botId !== '' ? $botId : '***').':'.str_repeat('•', 8).substr($token, -4);
    }

    private static function readSecret(string $key): ?string
    {
        $stored = Setting::getValue($key);

        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString($stored);
        } catch (DecryptException) {
            // Rows written before this feature (or after an APP_KEY rotation)
            // are returned as-is, matching SmsSettings — better a value the
            // admin can see and replace than a hard failure on every page load.
            $plain = $stored;
        }

        return trim($plain) !== '' ? trim($plain) : null;
    }

    private static function writeSecret(string $key, ?string $plain): void
    {
        $value = trim((string) $plain);

        Setting::setValue($key, $value !== '' ? Crypt::encryptString($value) : null);
    }
}
