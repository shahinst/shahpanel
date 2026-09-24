<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\Sanaei\SanaeiShareLinkBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * محتوای اشتراک اکانت را از پنل راه دور می‌گیرد و در accounts.subscription_cache
 * می‌نشاند تا «/sub/{token}» بتواند بدون هیچ تماس شبکه‌ای پاسخ بدهد.
 *
 * تمام کار سنگین این‌جاست، نه در مسیر درخواست: برای سنایی حتی ساختن نشانی
 * اشتراک هم یک POST /setting/all لازم دارد و اکانت‌سازی نباید منتظرش بماند.
 *
 * قانون سختِ این کلاس: شکست (پنل خوابیده، تایم‌اوت، خطای ۵۰۰، بدنهٔ خالی) هرگز
 * نباید کشِ قبلی را پاک کند. اگر کش موجود خالی شود، کانفیگ‌های یک مشتری
 * پول‌داده از اپلیکیشنش محو می‌شود؛ ماندنِ محتوای کمی قدیمی بی‌نهایت بهتر از
 * تحویل بدنهٔ خالی است. فقط پاسخ موفق و ناخالی جایگزین می‌شود.
 */
class RefreshSubscriptionCacheJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    /**
     * تلاش مجدد لازم نیست: هیچ استثنایی از handle() بیرون نمی‌زند و زمان‌بند هر
     * چند دقیقه یک‌بار ردیف‌هایی را که کششان به‌روز نشده دوباره انتخاب می‌کند.
     * این‌طور جدول failed_jobs هم با پنل‌های خوابیده پر نمی‌شود.
     */
    public int $tries = 1;

    /** تا چند کار همزمان برای یک اکانت روی هم نریزد (تمدید + زمان‌بند). */
    public int $uniqueFor = 300;

    public function __construct(public int $accountId) {}

    public function uniqueId(): string
    {
        return 'subscription-cache:'.$this->accountId;
    }

    /**
     * تنها نقطهٔ dispatch برای بقیهٔ کد: خودش تصمیم می‌گیرد اکانت اصلاً مفهوم
     * «لینک کانفیگ» دارد یا نه، تا AccountService درگیر این شرط‌ها نشود.
     * میکروتیک (PPP/WireGuard/OpenVPN/L2TP) و خانوادهٔ AnyConnect (سیسکو و
     * ocserv) کانفیگ‌یوآرآی ندارند و بی‌صدا رد می‌شوند.
     */
    public static function dispatchFor(Account $account): void
    {
        if (! $account->service_type->isPanelV2ray()) {
            return;
        }

        try {
            self::dispatch($account->id);
        } catch (Throwable $exception) {
            // این متد از داخل تراکنش خرید/تمدید صدا زده می‌شود؛ خطای صف نباید
            // کل تراکنش مالی را برگرداند. بدون کش هم اکانت سالم ساخته می‌شود و
            // زمان‌بند بعداً همان اکانت را برمی‌دارد.
            Log::warning('Subscription cache: dispatch failed', [
                'account_id' => $account->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function handle(SanaeiShareLinkBuilder $shareLinkBuilder): void
    {
        $account = Account::query()->with('server')->find($this->accountId);

        if ($account === null || ! $account->service_type->isPanelV2ray()) {
            return;
        }

        $url = $this->resolveRemoteUrl($account, $shareLinkBuilder);

        if ($url === null) {
            Log::warning('Subscription cache: no remote subscription URL', [
                'account_id' => $account->id,
                'server_id' => $account->server_id,
                'service_type' => $account->service_type->value,
            ]);

            return;
        }

        $body = $this->fetchBody($account, $url);

        if ($body === null) {
            // کش قبلی دست‌نخورده می‌ماند؛ دلیل شکست در fetchBody لاگ شده است.
            return;
        }

        // بدنه عیناً همان‌طور که پنل داده ذخیره می‌شود؛ نشانی کاربرمحور
        // (servers.client_host) هنگام تحویل در SubscriptionFeedService اعمال
        // می‌شود تا تغییر آن تنظیم، کش‌های موجود را بی‌اعتبار نکند.
        $account->forceFill([
            'subscription_cache' => $body,
            'subscription_cached_at' => now(),
        ])->save();
    }

    /**
     * نشانی اشتراک روی پنل راه دور. برای سنایی از همان سازندهٔ موجود استفاده
     * می‌شود (subId را حل می‌کند و در نهایت SanaeiService::buildSubscriptionLink
     * را صدا می‌زند) تا دو پیاده‌سازی موازی از ساخت لینک نداشته باشیم؛ همین متد
     * است که ممکن است به پنل درخواست بزند و دلیل صف‌شدنِ این کار است.
     */
    protected function resolveRemoteUrl(Account $account, SanaeiShareLinkBuilder $shareLinkBuilder): ?string
    {
        if ($account->service_type->isSanaei()) {
            return $shareLinkBuilder->subscriptionLinkForAccount($account);
        }

        if ($account->service_type->isPasarguard()) {
            return filled($account->pasarguard_subscription_url)
                ? (string) $account->pasarguard_subscription_url
                : null;
        }

        return filled($account->remnawave_subscription_url)
            ? (string) $account->remnawave_subscription_url
            : null;
    }

    /**
     * بدنهٔ آمادهٔ ذخیره، یا null برای هر شکلی از شکست. null یعنی «دست به کش نزن».
     */
    protected function fetchBody(Account $account, string $url): ?string
    {
        try {
            // withoutVerifying چون پنل‌های 3x-ui معمولاً گواهی self-signed یا
            // دامنهٔ ناهمخوان دارند و SanaeiService هم برای همین اندپوینت همین
            // کار را می‌کند؛ محتوای اشتراک راز تازه‌ای جابه‌جا نمی‌کند.
            $response = Http::connectTimeout(8)
                ->timeout(20)
                ->withoutVerifying()
                ->withHeaders([
                    // پنل برای User-Agent مرورگر صفحهٔ HTML می‌دهد و برای کلاینت
                    // v2ray فهرست کانفیگ؛ ما دومی را می‌خواهیم.
                    'Accept' => 'text/plain, application/octet-stream;q=0.9, */*;q=0.1',
                    'User-Agent' => 'v2rayNG/1.8.29',
                ])
                ->get($url);
        } catch (Throwable $exception) {
            Log::warning('Subscription cache: fetch error, keeping previous cache', [
                'account_id' => $account->id,
                'server_id' => $account->server_id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Subscription cache: fetch failed, keeping previous cache', [
                'account_id' => $account->id,
                'server_id' => $account->server_id,
                'status' => $response->status(),
            ]);

            return null;
        }

        $body = $this->normalizeBody($response->body());

        if ($body === '') {
            Log::warning('Subscription cache: empty body, keeping previous cache', [
                'account_id' => $account->id,
                'server_id' => $account->server_id,
            ]);

            return null;
        }

        return $body;
    }

    /**
     * خروجی همیشه «متن ساده، هر کانفیگ در یک خط» است. بدنهٔ 3x-ui بسته به
     * تنظیم subEncrypt می‌تواند base64 باشد و اگر همان‌طور ذخیره شود،
     * SubscriptionFeedService برای درخواست‌های b64 دوباره کدش می‌کند و کلاینت
     * چیزی از آن درنمی‌آورد؛ پس یک‌بار برای همیشه این‌جا رمزگشایی می‌شود.
     *
     * خط‌هایی که با «scheme://» شروع نمی‌شوند حذف می‌شوند تا صفحهٔ HTML خطا یا
     * فرم لاگین پنل به‌جای کانفیگ ذخیره نشود؛ نتیجه‌اش رشتهٔ خالی است و رشتهٔ
     * خالی یعنی «کش را دست نزن».
     */
    protected function normalizeBody(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            return '';
        }

        if (! str_contains($body, '://')) {
            $compact = (string) preg_replace('/\s+/', '', $body);

            foreach ([$compact, strtr($compact, '-_', '+/')] as $candidate) {
                $decoded = base64_decode($candidate, true);

                if (is_string($decoded) && str_contains($decoded, '://')) {
                    $body = trim($decoded);

                    break;
                }
            }
        }

        $lines = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && preg_match('#^[a-z][a-z0-9+.\-]*://#i', $line) === 1) {
                $lines[] = $line;
            }
        }

        return implode("\n", array_values(array_unique($lines)));
    }
}
