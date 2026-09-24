<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * خوراک اشتراک عمومی («/sub/{token}»).
 *
 * قانون سختِ این سرویس: هیچ متدی نباید به پنل راه دور (سنایی، مرزبان،
 * رمناویو، پاسارگارد، میکروتیک) درخواست بزند. کلاینت‌های VPN این نشانی را با
 * تایم‌اوت چندثانیه‌ای صدا می‌زنند و ربات‌های واسط هم روی تایم‌اوت ۳ ثانیه‌ای
 * دوباره تلاش می‌کنند؛ یک تماس شبکه‌ای کند کل زنجیره را می‌شکند. پس فقط از
 * ستون‌های همین دیتابیس خوانده می‌شود.
 */
class SubscriptionFeedService
{
    /**
     * توکن‌ها با Str::random ساخته می‌شوند، یعنی فقط حرف و رقم. هر چیز دیگری
     * حتی به دیتابیس هم نمی‌رسد تا اسکن‌کننده‌ها کوئری بی‌خود تولید نکنند.
     */
    protected const TOKEN_REGEX = '/^[A-Za-z0-9]{16,64}$/';

    public function __construct(
        protected SanaeiPortalService $sanaeiPortalService,
    ) {}

    /**
     * اکانت متناظر با توکن، یا null برای توکن ناشناس/ابطال‌شده/بدشکل. تمایزی
     * بین این سه حالت گذاشته نمی‌شود تا وجود یا نبود اکانت لو نرود.
     */
    public function resolve(string $token): ?Account
    {
        if (! $this->columnAvailable() || preg_match(self::TOKEN_REGEX, $token) !== 1) {
            return null;
        }

        return Account::query()
            ->where('subscription_token', $token)
            ->with('server')
            ->first();
    }

    /**
     * توکن فعلی اکانت؛ اگر نداشته باشد همین‌جا ساخته و ذخیره می‌شود. عمداً
     * lazy است تا مهاجرت لازم نباشد میلیون‌ها ردیف را backfill کند.
     */
    public function tokenFor(Account $account): string
    {
        $token = (string) ($account->subscription_token ?? '');

        if ($token !== '') {
            return $token;
        }

        return $this->issue($account);
    }

    /**
     * توکن تازه صادر می‌کند و با همین کار توکن قبلی را باطل می‌کند؛ پشتوانهٔ
     * عملیات «ابطال اشتراک» است.
     */
    public function issue(Account $account): string
    {
        $token = $this->generateToken();

        $account->forceFill(['subscription_token' => $token])->save();

        return $token;
    }

    public function urlFor(Account $account): string
    {
        // نشانی باید دقیقاً {panel}/sub/{token} باشد، چون ربات‌های موجود روی
        // explode("/sub/", $url) حساب می‌کنند و هر مسیر دیگری آن‌ها را می‌شکند.
        return route('subscription.show', $this->tokenFor($account));
    }

    /**
     * فهرست لینک‌های کانفیگی که به‌صورت محلی ذخیره شده‌اند.
     *
     * برای اکانت‌های پاسارگارد و رمناویو، نشانی اشتراک در همان ستون‌های اکانت
     * نگه داشته می‌شود و بدون شبکه در دسترس است. برای سنایی هیچ لینکی در پنل
     * ذخیره نشده و ساختنش نیازمند تماس با پنل راه دور است، پس این‌جا خالی
     * برمی‌گردد تا شرط «بدون تماس بیرونی» نشکند.
     *
     * @return list<string>
     */
    public function links(Account $account): array
    {
        $stored = $this->sanaeiPortalService->storedSubscriptionLink($account);

        return $stored !== null ? [$stored] : [];
    }

    /**
     * @param  list<string>  $links
     */
    public function body(array $links, bool $base64): string
    {
        $plain = implode("\n", $links);

        return $base64 ? base64_encode($plain) : $plain;
    }

    /**
     * هدر استانداردی که کلاینت‌های v2ray از آن مصرف و انقضا را نشان می‌دهند.
     * برای اکانت غیرفعال/منقضی/تمام‌شده هم فرستاده می‌شود، چون تنها راهِ
     * «به کاربر بگو چرا وصل نمی‌شود» همین است.
     */
    public function userInfoHeader(Account $account): string
    {
        $total = $account->isUnlimited() ? 0 : (int) $account->data_limit_bytes;
        $expire = $account->expiry_at?->getTimestamp() ?? 0;

        return sprintf(
            'upload=0; download=%d; total=%d; expire=%d',
            max(0, (int) $account->data_used_bytes),
            max(0, $total),
            max(0, $expire),
        );
    }

    protected function generateToken(): string
    {
        return Str::random((int) config('shahpanel.portal_token_length', 32));
    }

    /**
     * در فاصلهٔ بین کپی‌شدن کد و اجرای مهاجرت‌ها، ستون ممکن است هنوز نباشد؛
     * در آن بازه اندپوینت باید ۴۰۴ بدهد نه خطای ۵۰۰.
     */
    protected function columnAvailable(): bool
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        try {
            $cached = Schema::hasColumn('accounts', 'subscription_token');
        } catch (\Throwable) {
            $cached = false;
        }

        return $cached;
    }
}
