<?php

namespace App\Services\Marzban;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Services\SubscriptionFeedService;

/**
 * یک اکانت پنل را به شکل «کاربر مرزبان» درمی‌آورد.
 *
 * قانون سختِ این کلاس مثل SubscriptionFeedService است: هیچ تماسی با پنل راه دور.
 * ویزویز پیش از هر عملیات دوباره لاگین می‌کند و تایم‌اوت کوتاهی دارد؛ یک تماس
 * شبکه‌ای در این مسیر کل ربات را کند می‌کند. پس مصرف و انقضا از همین دیتابیس
 * خوانده می‌شوند (همان اعدادی که پنل هم در صفحه‌هایش نشان می‌دهد).
 */
class MarzbanUserPresenter
{
    public function __construct(
        protected SubscriptionFeedService $feed,
        protected MarzbanInboundTagService $tags,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Account $account): array
    {
        $account->loadMissing(['package', 'packageDuration', 'server', 'ownerSeller']);

        $protocol = $this->tags->protocolFor($account->service_type);
        $tag = $account->package !== null && $account->packageDuration !== null
            ? $this->tags->tagFor($account->package, $account->packageDuration)
            : null;

        $subscriptionUrl = $this->feed->urlFor($account);

        return [
            'username' => (string) $account->remote_username,
            'status' => $this->status($account),
            // بایت — قرارداد مرزبان. صفر در data_limit یعنی نامحدود و صفر در
            // expire یعنی بی‌انقضا؛ ربات‌ها هر دو را با شرط ساده تست می‌کنند.
            'used_traffic' => max(0, (int) $account->data_used_bytes),
            'lifetime_used_traffic' => max(0, (int) ($account->lifetime_used_bytes ?? $account->data_used_bytes)),
            'data_limit' => $account->isUnlimited() ? 0 : max(0, (int) $account->data_limit_bytes),
            'data_limit_reset_strategy' => $this->resetStrategy($account),
            'expire' => $account->expiry_at?->getTimestamp() ?? 0,
            'note' => (string) ($account->display_label ?? ''),
            // مرزبان زمان را بدون منطقه می‌دهد؛ همان قالب حفظ می‌شود تا پارسر
            // ربات به شاخهٔ ناشناخته نیفتد.
            'created_at' => $account->created_at?->format('Y-m-d\TH:i:s'),
            'links' => $this->links($account, $subscriptionUrl),
            // باید عیناً {panel}/sub/{token} باشد: ویزویز با explode("/sub/")
            // توکن را جدا می‌کند و بعد خودش روی مبدأ تنظیم‌شده صدایش می‌زند.
            'subscription_url' => $subscriptionUrl,
            'inbounds' => $tag !== null ? [$protocol => [$tag]] : new \stdClass(),
            'proxies' => [$protocol => $this->proxySettings($account)],
            'excluded_inbounds' => new \stdClass(),
            'online_at' => null,
            'sub_updated_at' => $account->subscription_cached_at?->format('Y-m-d\TH:i:s'),
            'sub_last_user_agent' => null,
            // پنل مفهوم on_hold ندارد؛ همیشه null تا ربات حالت انتظار نسازد.
            'on_hold_expire_duration' => null,
            'on_hold_timeout' => null,
            'auto_delete_in_days' => null,
            'admin' => [
                'username' => (string) ($account->ownerSeller?->username ?? ''),
            ],
        ];
    }

    /**
     * لینک‌های کانفیگ.
     *
     * قرارداد ربات‌ها می‌گوید پاسخِ ساخت کاربر «باید» links داشته باشد، ولی کشِ
     * محلیِ کانفیگ‌ها را یک جاب پس‌زمینه پر می‌کند و درست بعد از ساخت اکانت
     * سنایی هنوز خالی است. پر کردنش در همان درخواست یعنی یک تماس HTTP با پنل
     * راه دور در مسیر پاسخ — چیزی که تایم‌اوت ربات را می‌شکند.
     *
     * پس در آن بازه خودِ نشانی اشتراک به‌عنوان تنها لینک برگردانده می‌شود: هم
     * قرارداد حفظ می‌شود و هم چیزی که به مشتری می‌رسد واقعاً کار می‌کند (همهٔ
     * کلاینت‌های امروزی لینک اشتراک را می‌پذیرند).
     *
     * @return list<string>
     */
    protected function links(Account $account, string $subscriptionUrl): array
    {
        $links = $this->feed->links($account);

        return $links !== [] ? $links : [$subscriptionUrl];
    }

    /**
     * نگاشت وضعیت پنل به واژگان مرزبان.
     *
     * exhausted معادل limited است (حجم تمام شده). Pending یعنی اکانت هنوز روی
     * سرور راه‌اندازی نشده، پس disabled گزارش می‌شود نه active — گفتن «فعال» به
     * مشتری‌ای که وصل نمی‌شود بدترین جواب ممکن است.
     */
    public function status(Account $account): string
    {
        return match ($account->status) {
            AccountStatus::Active => 'active',
            AccountStatus::Exhausted => 'limited',
            AccountStatus::Expired => 'expired',
            default => 'disabled',
        };
    }

    /**
     * رمناویو استراتژی ریست دوره‌ای دارد؛ بقیه ندارند. نام‌ها به واژگان مرزبان
     * ترجمه می‌شوند تا ربات مقدار ناشناخته نبیند.
     */
    protected function resetStrategy(Account $account): string
    {
        $package = $account->package;

        if ($package === null || ! $account->service_type?->isRemnawave()) {
            return 'no_reset';
        }

        return match ($package->remnawaveTrafficStrategy()) {
            'DAY' => 'day',
            'WEEK' => 'week',
            'MONTH' => 'month',
            default => 'no_reset',
        };
    }

    /**
     * تنظیمات پروکسی. فقط سنایی uuid محلی ذخیره می‌کند؛ برای بقیه شیء خالی
     * برگردانده می‌شود (نه آرایهٔ خالی) تا در JSON به {} تبدیل شود و دسترسی
     * شیئی در ربات نشکند.
     */
    protected function proxySettings(Account $account): object
    {
        $uuid = trim((string) ($account->sanaei_client_uuid ?? ''));

        if ($uuid === '') {
            return new \stdClass();
        }

        return (object) ['id' => $uuid];
    }
}
