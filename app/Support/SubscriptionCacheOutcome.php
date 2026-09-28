<?php

namespace App\Support;

/**
 * نتیجهٔ یک دور تازه‌سازی کش اشتراک (RefreshSubscriptionCacheJob).
 *
 * چرا وجود دارد: آن کار برای هر شکلی از شکست فقط Log::warning می‌نوشت و void
 * برمی‌گشت، پس دکمهٔ «خواندن دوبارهٔ کانفیگ از پنل» چاره‌ای جز جمع کردن همهٔ
 * حالت‌ها زیر یک پیام مبهم نداشت. روی سرور واقعی، ۲۶ اکانت سنایی فقط به این
 * دلیل شکست می‌خوردند که سرورشان در پنل خاموش بود (servers.is_active = 0) و
 * پیام، اپراتور را سراغ لاگی می‌فرستاد که هرگز ساخته نشده بود.
 *
 * این کلاس فقط «چرا» را حمل می‌کند، نه متن نمایشی؛ ترجمه کار لایهٔ نمایش است.
 */
final class SubscriptionCacheOutcome
{
    /** بدنهٔ سالم گرفته شد و در کش نشست. */
    public const STORED = 'stored';

    /** ردیف اکانت پیدا نشد یا اصلاً مفهوم لینک کانفیگ ندارد. */
    public const NOT_APPLICABLE = 'not_applicable';

    /** اکانت به هیچ سروری وصل نیست. */
    public const SERVER_MISSING = 'server_missing';

    /** سرور در پنل خاموش است؛ هیچ درخواستی به پنل فرستاده نشد. */
    public const SERVER_DISABLED = 'server_disabled';

    /** سنایی: subId نه در ستون بود، نه پنل آن را برگرداند و نه از روی inbound چیزی ساخته شد. */
    public const NO_SUB_ID = 'no_sub_id';

    /**
     * سنایی: هیچ کلاینتی با این ایمیل/UUID روی پنل نیست — یعنی اکانت روی خودِ
     * پنل حذف یا دستکاری شده. جدا از NO_SUB_ID نگه داشته می‌شود چون کار اپراتور
     * کاملاً فرق می‌کند: آن‌یکی یعنی «کلاینت هست ولی لینکش را نمی‌دهد» و این‌یکی
     * یعنی «کلاینتی وجود ندارد». detail همان ایمیلی است که دنبالش گشتیم.
     */
    public const CLIENT_NOT_FOUND = 'client_not_found';

    /** پاسارگارد/رمناویو: ستون نشانی اشتراک خالی است. */
    public const NO_SUBSCRIPTION_URL = 'no_subscription_url';

    /** پنل در دسترس نبود یا خطای HTTP داد؛ detail متن دقیق خطاست. */
    public const UNREACHABLE = 'unreachable';

    /** پنل پاسخ داد ولی بدنه هیچ خط کانفیگی نداشت. */
    public const EMPTY_BODY = 'empty_body';

    /**
     * @param  string  $reason  یکی از ثابت‌های همین کلاس.
     * @param  string|null  $detail  تکهٔ متنی آمادهٔ جاگذاری در پیام (نام سرور،
     *                               متن خطای پنل). ترجمه‌نشده است و معنایش به
     *                               همان reason بند است.
     * @param  string|null  $body  بدنهٔ گرفته‌شده؛ فقط برای STORED پر است و
     *                             همین‌طور واکشی می‌تواند نتیجه و محتوا را با
     *                             یک مقدار برگرداند.
     */
    private function __construct(
        public readonly string $reason,
        public readonly ?string $detail = null,
        public readonly ?string $body = null,
    ) {}

    public static function fetched(string $body): self
    {
        return new self(self::STORED, body: $body);
    }

    public static function failed(string $reason, ?string $detail = null): self
    {
        return new self($reason, $detail);
    }

    public function succeeded(): bool
    {
        return $this->reason === self::STORED;
    }
}
