<?php

namespace App\Services\Marzban;

use App\Models\User;
use App\Services\ApiTokenService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * صدور توکن برای مسیر /api/admin/token (سازگار با مرزبان).
 *
 * چرا کش: ویزویز توکن را نگه نمی‌دارد و پیش از «هر» عملیات دوباره لاگین می‌کند،
 * آن هم با تایم‌اوت ۳ ثانیه‌ای curl. اگر هر بار یک bcrypt تازه اجرا و یک ردیف
 * api_tokens جدید ساخته شود، هم جواب از ۳ ثانیه رد می‌شود (bcrypt با cost بالا
 * روی سرور شلوغ صدها میلی‌ثانیه است) و هم جدول توکن‌ها متورم می‌شود.
 *
 * پس نتیجهٔ یک ورودِ موفق کش می‌شود. کلید کش از HMACِ «شناسهٔ کاربر + هشِ فعلی
 * رمز + خودِ رمز» ساخته می‌شود، یعنی:
 *  - تنها رمز درست همان کلید را تولید می‌کند، پس یافتنِ کش خودش اثباتِ رمز است
 *    و در hit به bcrypt نیازی نیست؛
 *  - با تغییر رمز، هشِ ذخیره‌شده عوض می‌شود و کش خودبه‌خود بی‌اعتبار می‌گردد.
 *
 * سیستم توکن دوم ساخته نمی‌شود: همان توکن mp_ پنل صادر می‌شود تا در فهرست
 * توکن‌های کاربر دیده و قابل ابطال باشد.
 */
class MarzbanTokenService
{
    public const TOKEN_NAME = 'marzban-facade';

    protected const CACHE_PREFIX = 'marzban-facade-token:';

    /** عمر توکن صادرشده. */
    protected const TOKEN_TTL_DAYS = 30;

    /** کش کمی کوتاه‌تر از توکن، تا هرگز توکنِ در حال انقضا تحویل نرود. */
    protected const CACHE_TTL_DAYS = 20;

    /**
     * ربات‌ها پرتماس‌اند (ویزویز برای هر عملیات دو درخواست می‌زند)، ولی سقف
     * موجود در ApiThrottle حداکثر ۶۰۰ را می‌پذیرد.
     */
    protected const RATE_LIMIT_PER_MINUTE = 600;

    /**
     * توانایی‌هایی که این توکن می‌گیرد.
     *
     * همان توکن روی /api/v1 هم کار می‌کند، پس عمداً محدود می‌شود به کاری که
     * ربات لازم دارد؛ مثلاً resellers:read داده نمی‌شود.
     *
     * @var list<string>
     */
    protected const ABILITIES = [
        'accounts:read',
        'accounts:create',
        'accounts:renew',
        'accounts:update',
        'catalog:read',
    ];

    public function __construct(protected ApiTokenService $tokens) {}

    /**
     * رمز را بررسی و توکن قابل استفاده برمی‌گرداند؛ null یعنی رمز غلط است.
     *
     * بررسی نقش/وضعیت کاربر این‌جا نیست: صدور توکن خودش با
     * ApiTokenService::assertUserMayHoldToken آن را اعمال می‌کند و کنترلر هم
     * پیش از رسیدن به این‌جا پیام مناسب را می‌دهد.
     */
    public function authenticate(User $user, string $password): ?string
    {
        $key = $this->cacheKey($user, $password);
        $cached = Cache::get($key);

        // resolve() تضمین می‌کند توکن کش‌شده ابطال یا منقضی نشده باشد؛ یک
        // کوئری ایندکس‌دار است و ارزانی‌اش همان چیزی است که این مسیر می‌خواهد.
        if (is_string($cached) && $cached !== '' && $this->tokens->resolve($cached) !== null) {
            return $cached;
        }

        if (! Hash::check($password, (string) $user->password)) {
            return null;
        }

        $issued = $this->tokens->issue(
            $user,
            self::TOKEN_NAME,
            self::ABILITIES,
            now()->addDays(self::TOKEN_TTL_DAYS),
            null,
            self::RATE_LIMIT_PER_MINUTE,
        );

        $plain = $issued['plain_text'];

        Cache::put($key, $plain, now()->addDays(self::CACHE_TTL_DAYS));

        return $plain;
    }

    /**
     * رمز خام هرگز ذخیره نمی‌شود؛ فقط از آن یک کلید مشتق می‌شود که بدون
     * APP_KEY قابل ساختن نیست.
     */
    protected function cacheKey(User $user, string $password): string
    {
        $material = implode('|', [
            (string) $user->getKey(),
            (string) $user->password,
            $password,
        ]);

        return self::CACHE_PREFIX.hash_hmac('sha256', $material, (string) config('app.key'));
    }
}
