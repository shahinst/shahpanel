<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\Server;
use App\Services\Sanaei\SanaeiShareLinkBuilder;
use App\Services\SanaeiService;
use App\Support\SubscriptionCacheOutcome;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
 *
 * هر مسیرِ شکست یک دلیلِ مشخص برمی‌گرداند (SubscriptionCacheOutcome) تا فراخوانِ
 * همگام — دکمهٔ «خواندن دوبارهٔ کانفیگ از پنل» — عین همان اتفاق را به کاربر
 * بگوید. پیش از این همه‌چیز زیر یک پیام مبهم پنهان می‌شد و روی سرور واقعی،
 * سرورِ خاموش‌شده در پنل هم همان پیام را می‌داد که پنلِ خراب.
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

    /**
     * مسیر صف: نتیجه دور ریخته می‌شود چون کسی آن‌طرف نیست که ببیندش و دلیل
     * شکست همان‌جا در refresh() لاگ شده است.
     */
    public function handle(SanaeiShareLinkBuilder $shareLinkBuilder, SanaeiService $sanaeiService): void
    {
        $this->refresh($shareLinkBuilder, $sanaeiService);
    }

    /**
     * اجرای همین‌جا و همین‌لحظه، با نتیجهٔ خوانا — برای دکمهٔ پشتیبانی.
     *
     * چرا این و نه dispatchSync: dispatchSync کار را از صفِ sync می‌گذراند و صف
     * نمونهٔ تازه‌ای از payload می‌سازد؛ پس نه مقدار بازگشتی handle() به فراخوان
     * می‌رسد و نه هیچ خاصیتی که روی نمونهٔ ساختهٔ خودش بنشیند. برای گفتن «چرا
     * نشد» باید همین پروسه نتیجه را در دست بگیرد.
     */
    public static function runNowFor(Account $account): SubscriptionCacheOutcome
    {
        return (new self($account->id))->refresh(
            app(SanaeiShareLinkBuilder::class),
            app(SanaeiService::class),
        );
    }

    /**
     * همان کار handle()، ولی می‌گوید چه شد. هیچ شکستی به نوشتن در کش نمی‌رسد؛
     * فقط شاخهٔ موفق storeBody را صدا می‌زند.
     */
    public function refresh(SanaeiShareLinkBuilder $shareLinkBuilder, SanaeiService $sanaeiService): SubscriptionCacheOutcome
    {
        $account = Account::query()->with('server')->find($this->accountId);

        if ($account === null || ! $account->service_type->isPanelV2ray()) {
            return SubscriptionCacheOutcome::failed(SubscriptionCacheOutcome::NOT_APPLICABLE);
        }

        $server = $account->server;

        if ($server === null) {
            return SubscriptionCacheOutcome::failed(SubscriptionCacheOutcome::SERVER_MISSING);
        }

        // سرورِ خاموش در پنل: پیش از هر تماس شبکه‌ای برمی‌گردیم. روی سرور واقعی
        // تمام شکست‌های سنایی همین یک حالت بود و چون هیچ‌جا اعلام نمی‌شد،
        // پشتیبانی دنبال خرابیِ پنل می‌گشت. تماس با سرور خاموش هم بی‌معناست:
        // اکانت‌هایش روی پنل هم سرویس نمی‌دهند.
        if (! $server->is_active) {
            return SubscriptionCacheOutcome::failed(
                SubscriptionCacheOutcome::SERVER_DISABLED,
                (string) $server->name,
            );
        }

        // سنایی اول از مسیر چندکاندیدای خودِ SanaeiService می‌آید. یک GET ساده
        // روی لینک ساخته‌شده روی نصب پیش‌فرض 3x-ui شکست می‌خورد: سرور ساب روی
        // پورت دیگری و با HTTP ساده بالا می‌آید، پس لینک https به آن پورت خطای
        // TLS می‌دهد. آن متد چند نشانی و چند طرح را امتحان می‌کند و همان چیزی را
        // برمی‌گرداند که کلاینت می‌بیند.
        if ($account->service_type->isSanaei()) {
            $lines = $this->fetchSanaeiLines($account, $server, $sanaeiService, $shareLinkBuilder);

            if ($lines !== null) {
                $this->storeBody($account, $lines);

                return SubscriptionCacheOutcome::fetched($lines);
            }

            // مسیر بالا از subId می‌آید و اکانت‌های قدیمی روی پنل subId ندارند
            // (x-ui، درون‌ریزی‌شده، یا هر کلاینتی که با API بدون subId ساخته شده).
            // تا پیش از این همان‌جا بن‌بست بود و کاربر پیام «پنل subId نداد»
            // می‌گرفت، درحالی‌که اکانتش روی پنل زنده بود و ترافیکش هم سینک
            // می‌شد. تعریف خودِ inbound برای ساختن کانفیگ کافی است، پس قبل از
            // اعلام شکست از همان‌جا می‌سازیم.
            $fromInbounds = $shareLinkBuilder->configLinksFromInbounds($account);

            if ($fromInbounds['links'] !== []) {
                $body = implode("
", $fromInbounds['links']);

                $this->storeBody($account, $body);

                return SubscriptionCacheOutcome::fetched($body);
            }

            // نه لینک ساخته شد و نه اصلاً کلاینتی پیدا شد: این دیگر «subId
            // نداریم» نیست، «اکانت روی پنل نیست» است و کار اپراتور فرق می‌کند.
            if (! $fromInbounds['found']) {
                Log::channel('sanaei')->warning('Subscription cache: client missing on panel', [
                    'account_id' => $account->id,
                    'server_id' => $account->server_id,
                    'client_email' => $account->client_email,
                ]);

                return SubscriptionCacheOutcome::failed(
                    SubscriptionCacheOutcome::CLIENT_NOT_FOUND,
                    (string) ($account->client_email ?? $account->remote_username ?? ''),
                );
            }
        }

        $url = $this->resolveRemoteUrl($account, $shareLinkBuilder);

        if ($url === null) {
            Log::channel(self::logChannelFor($account))->warning('Subscription cache: no remote subscription URL', [
                'account_id' => $account->id,
                'server_id' => $account->server_id,
                'service_type' => $account->service_type->value,
            ]);

            // دو راهِ مختلف به این نقطه می‌رسد و اپراتور باید بداند کدام: سنایی
            // یعنی subId حل نشد، پاسارگارد/رمناویو یعنی ستون نشانی اشتراک خالی
            // است. یک پیام مشترک، هر دو را به بیراهه می‌فرستاد.
            return SubscriptionCacheOutcome::failed($account->service_type->isSanaei()
                ? SubscriptionCacheOutcome::NO_SUB_ID
                : SubscriptionCacheOutcome::NO_SUBSCRIPTION_URL);
        }

        $outcome = $this->fetchBody($account, $url);

        if (! $outcome->succeeded()) {
            // کش قبلی دست‌نخورده می‌ماند و دلیل شکست با خودِ نتیجه بالا می‌رود.
            return $outcome;
        }

        $this->storeBody($account, (string) $outcome->body);

        return $outcome;
    }

    /**
     * کانال لاگِ همان پنل. سرنخ‌های مسیر سنایی در sanaei.log می‌نشیند و
     * نوشتن رویدادهای پاسارگارد/رمناویو در همان فایل، اپراتور را گمراه می‌کرد —
     * همان‌طور که پیامِ قبلیِ دکمه، همه را به sanaei.log حواله می‌داد.
     *
     * فقط برای اکانت پنلِ v2ray صدا زده می‌شود (شرط isPanelV2ray بالای کار).
     */
    public static function logChannelFor(Account $account): string
    {
        return match (true) {
            $account->service_type->isSanaei() => 'sanaei',
            $account->service_type->isPasarguard() => 'pasarguard',
            default => 'remnawave',
        };
    }

    /**
     * بدنه عیناً همان‌طور که پنل داده ذخیره می‌شود؛ نشانی کاربرمحور
     * (servers.client_host) هنگام تحویل در SubscriptionFeedService اعمال می‌شود
     * تا تغییر آن تنظیم، کش‌های موجود را بی‌اعتبار نکند.
     */
    protected function storeBody(Account $account, string $body): void
    {
        $account->forceFill([
            'subscription_cache' => $body,
            'subscription_cached_at' => now(),
        ])->save();
    }

    /**
     * فهرست کانفیگ سنایی از مسیر چندکاندیدای SanaeiService. null یعنی نشد و
     * باید سراغ GET مستقیم رفت؛ رشتهٔ خالی هرگز برنمی‌گردد چون ذخیرهٔ بدنهٔ
     * خالی یعنی پاک کردن کانفیگ‌های کلاینت.
     *
     * دلیلِ شکست را برنمی‌گرداند چون شکستش پایانی نیست: GET مستقیم بعد از آن
     * ممکن است جواب بدهد و دلیلِ نهایی از همان مسیر می‌آید.
     */
    protected function fetchSanaeiLines(
        Account $account,
        Server $server,
        SanaeiService $sanaeiService,
        SanaeiShareLinkBuilder $shareLinkBuilder
    ): ?string {
        // چرا resolveSubId و نه خواندن مستقیم ستون: اکانت‌های درون‌ریزی‌شده از پنل
        // سنایی ستون sanaei_sub_id را خالی دارند و خواندن مستقیم باعث می‌شد این
        // کار بی‌صدا برگردد، کش هرگز پر نشود و مشتری فقط لینک سابسکرایب ببیند.
        $subId = $shareLinkBuilder->resolveSubId($account);

        if ($subId === null) {
            // پایانِ راه نیست — refresh() بعد از این از روی تعریف inbound
            // می‌سازد — ولی همچنان ثبت می‌شود، چون نبودن subId روی پنل یعنی
            // لینک اشتراکِ آن اکانت هم کار نمی‌کند و ارزش دیدن دارد.
            Log::channel('sanaei')->warning('Subscription cache: unresolved Sanaei sub id', [
                'account_id' => $account->id,
                'server_id' => $account->server_id,
                'client_email' => $account->client_email,
            ]);

            return null;
        }

        try {
            $links = $sanaeiService->fetchSubscriptionConfigLinks($server, $subId);
        } catch (Throwable $exception) {
            Log::channel('sanaei')->warning('Subscription cache: Sanaei fetch failed, falling back', [
                'account_id' => $account->id,
                'server_id' => $account->server_id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $lines = [];

        foreach ($links as $link) {
            $link = trim((string) $link);

            if ($link !== '' && str_contains($link, '://')) {
                $lines[] = $link;
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
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
     * fetched با بدنهٔ آمادهٔ ذخیره، یا یک شکستِ دلیل‌دار. هر چیزی جز fetched
     * یعنی «دست به کش نزن».
     *
     * متن خطای پنل هم برمی‌گردد (بریده، چون خطاهای cURL بلندند) تا کاربر لازم
     * نباشد برای فهمیدن «چرا نشد» سراغ فایل لاگ برود.
     */
    protected function fetchBody(Account $account, string $url): SubscriptionCacheOutcome
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
            Log::channel(self::logChannelFor($account))->warning('Subscription cache: fetch error, keeping previous cache', [
                'account_id' => $account->id,
                'server_id' => $account->server_id,
                'error' => $exception->getMessage(),
            ]);

            return SubscriptionCacheOutcome::failed(
                SubscriptionCacheOutcome::UNREACHABLE,
                Str::limit($exception->getMessage(), 160),
            );
        }

        if (! $response->successful()) {
            Log::channel(self::logChannelFor($account))->warning('Subscription cache: fetch failed, keeping previous cache', [
                'account_id' => $account->id,
                'server_id' => $account->server_id,
                'status' => $response->status(),
            ]);

            return SubscriptionCacheOutcome::failed(
                SubscriptionCacheOutcome::UNREACHABLE,
                'HTTP '.$response->status(),
            );
        }

        $body = $this->normalizeBody($response->body());

        if ($body === '') {
            Log::channel(self::logChannelFor($account))->warning('Subscription cache: empty body, keeping previous cache', [
                'account_id' => $account->id,
                'server_id' => $account->server_id,
            ]);

            return SubscriptionCacheOutcome::failed(SubscriptionCacheOutcome::EMPTY_BODY);
        }

        return SubscriptionCacheOutcome::fetched($body);
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
