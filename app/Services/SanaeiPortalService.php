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
    ) {}

    /**
     * @return array{subscription_link: ?string, subscription_qr: ?string}
     */
    public function portalAssets(Account $account): array
    {
        $account->loadMissing('server');

        if ($account->server?->isPasarguard() && filled($account->pasarguard_subscription_url)) {
            $link = (string) $account->pasarguard_subscription_url;

            return [
                'subscription_link' => $link,
                'subscription_qr' => $this->qrBase64($link),
            ];
        }

        if (filled($account->remnawave_subscription_url)) {
            $link = (string) $account->remnawave_subscription_url;

            return [
                'subscription_link' => $link,
                'subscription_qr' => $this->qrBase64($link),
            ];
        }

        if (! $account->service_type->isSanaei()) {
            return [
                'subscription_link' => null,
                'subscription_qr' => null,
            ];
        }

        $this->backfillSubId($account);

        $subscriptionLink = $this->shareLinkBuilder->subscriptionLinkForAccount($account);

        return [
            'subscription_link' => $subscriptionLink,
            'subscription_qr' => $subscriptionLink !== null ? $this->qrBase64($subscriptionLink) : null,
        ];
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
