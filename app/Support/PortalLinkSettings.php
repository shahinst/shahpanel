<?php

namespace App\Support;

use App\Models\Setting;

/**
 * عمر لینک پورتال مشتری، قابل تنظیم از پنل.
 *
 * تا پیش از این عمر لینک یک ثابت در config بود و مالک پنل برای عوض کردنش باید
 * فایل ویرایش می‌کرد. مقدار همیشه به «دقیقه» ذخیره می‌شود و واحد (دقیقه/ساعت/روز)
 * فقط برای فرم است؛ هر جای دیگر کد یک عدد دقیقه می‌بیند و چیزی نمی‌شکند. به
 * مایگریشن نیازی نیست؛ مثل بقیهٔ تنظیمات یک ردیف در جدول settings است.
 */
final class PortalLinkSettings
{
    public const KEY_TTL_MINUTES = 'portal_link_ttl_minutes';

    /** سقف عمدی: ۳۶۵ روز بر حسب دقیقه. لینکِ همیشه‌زنده یعنی لینکِ بی‌محافظ. */
    public const MAX_TTL_MINUTES = 525_600;

    /** واحدهای مجاز فرم، هر کدام با ضریب تبدیل به دقیقه. */
    public const UNITS = [
        'minutes' => 1,
        'hours' => 60,
        'days' => 1440,
    ];

    /** مقدار ذخیره‌شده، یا null وقتی ادمین چیزی تنظیم نکرده (یا ردیف خراب است). */
    public static function ttlMinutes(): ?int
    {
        $stored = Setting::getValue(self::KEY_TTL_MINUTES);

        if ($stored === null || trim($stored) === '' || ! ctype_digit(trim($stored))) {
            return null;
        }

        $minutes = (int) trim($stored);

        if ($minutes < 1 || $minutes > self::MAX_TTL_MINUTES) {
            return null;
        }

        return $minutes;
    }

    /** @return int دقیقهٔ ذخیره‌شده، تا فراخوان بداند چه چیزی نشست */
    public static function setTtlMinutes(int $minutes): int
    {
        $minutes = max(1, min(self::MAX_TTL_MINUTES, $minutes));

        Setting::setValue(self::KEY_TTL_MINUTES, (string) $minutes);

        return $minutes;
    }

    /** برگرداندن به حالت پیش‌فرض config (نبودِ ردیف = رفتار نسخه‌های قبل). */
    public static function clear(): void
    {
        Setting::setValue(self::KEY_TTL_MINUTES, null);
    }

    /** null یعنی ورودی فرم نامعتبر بود؛ خطا را فراخوان می‌سازد. */
    public static function minutesFor(int $amount, string $unit): ?int
    {
        if ($amount < 1 || ! array_key_exists($unit, self::UNITS)) {
            return null;
        }

        $minutes = $amount * self::UNITS[$unit];

        if ($minutes < 1 || $minutes > self::MAX_TTL_MINUTES) {
            return null;
        }

        return $minutes;
    }

    /**
     * بزرگ‌ترین واحدی که عدد در آن رُند می‌شود — ۴۳۲۰ دقیقه در فرم «۳ روز» دیده
     * می‌شود نه یک عدد چهاررقمی. واحد جداگانه ذخیره نمی‌شود چون همین کافی است.
     */
    public static function unitFor(int $minutes): string
    {
        if ($minutes % self::UNITS['days'] === 0) {
            return 'days';
        }

        if ($minutes % self::UNITS['hours'] === 0) {
            return 'hours';
        }

        return 'minutes';
    }

    public static function amountFor(int $minutes): int
    {
        return max(1, intdiv($minutes, self::UNITS[self::unitFor($minutes)]));
    }

    /** مثل «۳ روز» / «3 days» — برای نمایش به ادمین و به مشتری. */
    public static function describe(int $minutes): string
    {
        $minutes = max(1, $minutes);
        $unit = self::unitFor($minutes);

        return persian_digits(self::amountFor($minutes)).' '.__('security.portal_link_ttl_unit_'.$unit);
    }
}
