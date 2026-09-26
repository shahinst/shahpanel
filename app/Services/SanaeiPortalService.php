<?php

namespace App\Services;

use App\Models\Account;
use App\Services\Sanaei\SanaeiShareLinkBuilder;
use App\Services\SanaeiService;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

class SanaeiPortalService
{
    public function __construct(
        protected SanaeiShareLinkBuilder $shareLinkBuilder,
        protected SanaeiService $sanaeiService,
        protected ClientAddressRewriter $clientAddressRewriter,
    ) {}

    /**
     * تنها لینک اشتراکی که بدون هیچ تماسی با پنل راه دور در دسترس است، یعنی
     * همان چیزی که هنگام ساخت اکانت در ستون‌های خودِ ما ذخیره شده. اندپوینت
     * عمومی /sub که باید بسیار سریع پاسخ بدهد تنها از همین متد استفاده می‌کند
     * تا ترتیب اولویت لینک‌ها در یک جا بماند.
     *
     * این نشانی‌ها را خودِ پنل راه دور برگردانده و روی میزبان مدیریتی‌اند؛ چون
     * هر دو مصرف‌کنندهٔ این متد کاربرمحورند، نشانی کاربرمحور همین‌جا اعمال
     * می‌شود. ستون‌های خام دست‌نخورده می‌مانند تا هم‌گام‌سازی با پنل نشکند.
     */
    public function storedSubscriptionLink(Account $account): ?string
    {
        $account->loadMissing('server');
        $server = $account->server;

        $stored = null;

        if ($server?->isPasarguard() && filled($account->pasarguard_subscription_url)) {
            $stored = (string) $account->pasarguard_subscription_url;
        } elseif (filled($account->remnawave_subscription_url)) {
            $stored = (string) $account->remnawave_subscription_url;
        }

        if ($stored === null) {
            return null;
        }

        return $server !== null
            ? $this->clientAddressRewriter->rewriteUrlHost($stored, $server)
            : $stored;
    }

    /**
     * @return array{subscription_link: ?string, subscription_qr: ?string, config_links: list<array{uri: string, remark: string, qr: string}>}
     */
    public function portalAssets(Account $account): array
    {
        // لینک‌های مستقیم در هر سه مسیر خروجی یکسان‌اند، پس یک‌بار ساخته می‌شوند.
        $configLinks = $this->configLinks($account);

        $stored = $this->storedSubscriptionLink($account);

        if ($stored !== null) {
            return [
                'subscription_link' => $stored,
                'subscription_qr' => $this->qrBase64($stored),
                'config_links' => $configLinks,
            ];
        }

        if (! $account->service_type->isSanaei()) {
            return [
                'subscription_link' => null,
                'subscription_qr' => null,
                'config_links' => $configLinks,
            ];
        }

        $this->backfillSubId($account);

        // نسخهٔ کاربرمحور: همین لینک در پورتال و QR به دست کاربر می‌رسد.
        $subscriptionLink = $this->shareLinkBuilder->clientSubscriptionLinkForAccount($account);

        return [
            'subscription_link' => $subscriptionLink,
            'subscription_qr' => $subscriptionLink !== null ? $this->qrBase64($subscriptionLink) : null,
            'config_links' => $configLinks,
        ];
    }

    /**
     * لینک‌های مستقیم کانفیگ (vless/vmess/trojan/ss) همراه با QR هرکدام.
     *
     * چرا لازم است: بخشی از کلاینت‌ها نشانی سابسکرایب را نمی‌فهمند و فقط با
     * URI مستقیم وصل می‌شوند؛ بدون این جعبه، پشتیبانی باید دستی لینک بفرستد.
     *
     * چرا بر اساس طرح (scheme) فیلتر می‌شود: SubscriptionFeedService::links()
     * وقتی کشِ محتوا خالی باشد نشانی سابسکرایبِ ذخیره‌شده را برمی‌گرداند؛ همان
     * چیزی که در جعبهٔ سابسکرایب نشسته است و تکرارش در جعبهٔ «لینک مستقیم» فقط
     * کاربر را گمراه می‌کند. پس فقط خطوطی می‌مانند که واقعاً URI کانفیگ‌اند و
     * تا پر شدن کش (کار RefreshSubscriptionCacheJob) فهرست خالی می‌ماند.
     *
     * چرا با app() و نه تزریق در سازنده: SubscriptionFeedService خودش همین
     * سرویس را در سازنده می‌گیرد و تزریق دوطرفه کانتینر را در حلقه می‌اندازد.
     *
     * @return list<array{uri: string, remark: string, qr: string}>
     */
    public function configLinks(Account $account): array
    {
        $links = [];

        foreach (app(SubscriptionFeedService::class)->links($account) as $line) {
            $uri = trim((string) $line);
            $scheme = strtolower((string) strstr($uri, '://', true));

            if (! in_array($scheme, ['vless', 'vmess', 'trojan', 'ss'], true)) {
                continue;
            }

            $links[] = [
                'uri' => $uri,
                'remark' => $this->configLinkRemark($uri, $scheme),
                'qr' => $this->qrBase64($uri),
            ];
        }

        return $links;
    }

    /**
     * نامی که کنار هر QR چاپ می‌شود. یک اکانت سنایی با دو اینباند دو لینک دارد
     * و بدون این نام معلوم نیست کدام QR مال کدام سرویس است. اگر پنل نامی
     * نگذاشته باشد نام طرح جای خالی را پر می‌کند تا برچسب خالی چاپ نشود.
     */
    protected function configLinkRemark(string $uri, string $scheme): string
    {
        if ($scheme === 'vmess') {
            // بدنهٔ vmess:// یک JSON کدشده با base64 است و نام در کلید ps می‌نشیند.
            $payload = json_decode((string) base64_decode(substr($uri, 8), true), true);
            $remark = is_array($payload) ? trim((string) ($payload['ps'] ?? '')) : '';
        } else {
            $remark = trim(rawurldecode((string) (parse_url($uri, PHP_URL_FRAGMENT) ?? '')));
        }

        return $remark !== '' ? $remark : strtoupper($scheme);
    }

    protected function backfillSubId(Account $account): void
    {
        if (filled($account->sanaei_sub_id) || ! $account->sanaei_client_uuid) {
            return;
        }

        $account->loadMissing('server');
        $server = $account->server;

        if ($server === null) {
            return;
        }

        try {
            $email = trim((string) ($account->client_email ?? $account->remote_username ?? ''));
            $client = $email !== ''
                ? $this->sanaeiService->resolvePanelClient(
                    $server,
                    $email,
                    (string) $account->sanaei_client_uuid,
                    $account->sanaei_inbound_id ?: null
                )
                : null;
            $subId = $this->sanaeiService->extractSubId($client);

            if ($subId !== null) {
                $account->forceFill(['sanaei_sub_id' => $subId])->save();
            }
        } catch (\Throwable) {
            // Portal still attempts manual link build.
        }
    }

    public function qrBase64(string $content): string
    {
        $result = Builder::create()
            ->writer(new PngWriter)
            ->data($content)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->size(480)
            ->margin(20)
            ->build();

        return base64_encode($result->getString());
    }
}
