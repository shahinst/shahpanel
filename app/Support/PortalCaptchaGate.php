<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * حالت «کپچای حل‌شده» برای یک لینک پورتال.
 *
 * چرا این‌طور: مشتری با هر بار باز کردن همان لینک نباید دوباره کپچا ببیند، ولی
 * حل کردن کپچای یک لینک هم نباید در لینک دیگری قابل استفاده باشد. پس حالت
 * حل‌شده به اثرانگشت همان توکن گره می‌خورد و عمر کوتاه دارد.
 */
final class PortalCaptchaGate
{
    private const SESSION_KEY = 'portal_captcha_solved';

    /** سشن مشتری نباید بی‌نهایت بزرگ شود؛ چند لینک آخر کافی است. */
    private const MAX_ENTRIES = 12;

    public static function isSolved(Request $request, string $token): bool
    {
        return array_key_exists(self::fingerprint($token), self::solvedEntries($request));
    }

    public static function markSolved(Request $request, string $token): void
    {
        $solved = self::solvedEntries($request);
        $solved[self::fingerprint($token)] = now()->addMinutes(self::ttlMinutes())->getTimestamp();

        if (count($solved) > self::MAX_ENTRIES) {
            asort($solved);
            $solved = array_slice($solved, -self::MAX_ENTRIES, null, true);
        }

        $request->session()->put(self::SESSION_KEY, $solved);
    }

    public static function forget(Request $request, string $token): void
    {
        $solved = self::solvedEntries($request);
        unset($solved[self::fingerprint($token)]);

        $request->session()->put(self::SESSION_KEY, $solved);
    }

    public static function ttlMinutes(): int
    {
        return max(5, (int) config('shahpanel.portal_captcha_session_minutes', 60));
    }

    /**
     * ردیف‌های منقضی همین‌جا دور ریخته می‌شوند تا هیچ‌جای دیگر لازم نباشد
     * انقضا را چک کند.
     *
     * @return array<string, int>
     */
    private static function solvedEntries(Request $request): array
    {
        $raw = $request->session()->get(self::SESSION_KEY);

        if (! is_array($raw)) {
            return [];
        }

        $now = now()->getTimestamp();
        $solved = [];

        foreach ($raw as $fingerprint => $expiresAt) {
            if (is_string($fingerprint) && is_numeric($expiresAt) && (int) $expiresAt > $now) {
                $solved[$fingerprint] = (int) $expiresAt;
            }
        }

        return $solved;
    }

    /**
     * اثرانگشت به‌جای خود توکن در سشن می‌نشیند تا توکن پورتال هیچ‌جا ذخیره نشود،
     * و چون کلید به همان توکن گره خورده، حالت حل‌شدهٔ یک لینک روی لینک دیگر
     * جواب نمی‌دهد.
     */
    private static function fingerprint(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
