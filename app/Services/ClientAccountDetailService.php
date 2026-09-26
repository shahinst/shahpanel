<?php

namespace App\Services;

use App\Enums\AccountCategory;
use App\Enums\InvoiceType;
use App\Enums\MoneyCurrency;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\ServerInterface;
use App\Models\Transaction;
use App\Models\User;
use Throwable;

class ClientAccountDetailService
{
    public function __construct(
        protected WireGuardConfigService $wireGuardConfigService,
        protected SanaeiPortalService $sanaeiPortalService,
        protected AccountBillingPackageService $billingPackageService,
        protected AccountRenewalPricingService $renewalPricing,
        protected ClientDisplayPricingService $displayPricingService,
        protected ClientPortalEconomicsService $clientPortalEconomics,
        protected EndUserService $endUserService,
        protected MikrotikService $mikrotikService,
        protected MikrotikProfileService $mikrotikProfileService,
        protected ServerL2tpIpsecService $l2tpIpsec,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Account $account, ?User $clientUser = null): array
    {
        $account->load([
            'clientUser',
            'package.category',
            'packageDuration',
            'server',
            'ownerSeller',
            'invoices' => fn ($q) => $q->orderByDesc('issued_at'),
            'transactions' => fn ($q) => $q->orderByDesc('created_at')->limit(20),
        ]);

        $wireguard = [
            'config' => null,
            'qr_base64' => null,
            'config_download_route' => null,
            'qr_download_route' => null,
            'error' => null,
        ];

        if ($account->service_type === ServiceType::Wireguard) {
            try {
                $wireguard['config'] = $this->wireGuardConfigService->buildConfig($account);
                $wireguard['qr_base64'] = base64_encode($this->wireGuardConfigService->buildQrPng($account));
            } catch (Throwable $exception) {
                $wireguard['error'] = $exception->getMessage();
            }
        }

        $panelV2ray = $account->service_type->isPanelV2ray()
            ? $this->sanaeiPortalService->portalAssets($account)
            : ['subscription_link' => null, 'subscription_qr' => null, 'config_links' => []];

        $purchaseInvoice = $account->invoices
            ->first(fn (Invoice $invoice): bool => $invoice->type === InvoiceType::NewAccount);

        $renewalInvoices = $account->invoices
            ->filter(fn (Invoice $invoice): bool => $invoice->type === InvoiceType::Renewal)
            ->values();

        $renewalPrice = null;
        // ارز تمدید از بسته سرویس گرفته می‌شود؛ اگر بسته حذف شده باشد ارز پیش‌فرض پنل استفاده می‌شود.
        $renewalCurrency = $account->package?->moneyCurrency() ?? MoneyCurrency::default();
        $canRenew = $account->package !== null
            && app(PackageCategoryService::class)->isPackageAvailableForRenewal($account->package)
            && ! $account->isRefunded();

        if ($canRenew && $clientUser !== null) {
            try {
                $accountForRenewal = $this->billingPackageService->syncBillingPackage($account);
                $owner = $this->endUserService->resolvePortalOwner($clientUser);

                if ($accountForRenewal->packageDuration !== null) {
                    $quote = $this->clientPortalEconomics->quote(
                        $owner,
                        $accountForRenewal->packageDuration,
                        $this->renewalPricing->billableDataGb($accountForRenewal),
                        $this->displayPricingService->renewalDisplayUnitPrice($clientUser, $accountForRenewal->packageDuration),
                        forRenewal: true,
                    );
                    $renewalPrice = $quote['display_total'];
                }
            } catch (Throwable) {
                $renewalPrice = null;
            }
        }

        $usedBytes = (int) $account->data_used_bytes;
        $limitBytes = $account->isUnlimited() ? null : (int) $account->data_limit_bytes;
        $remainingBytes = $limitBytes !== null ? max(0, $limitBytes - $usedBytes) : null;
        $usagePercent = ($limitBytes !== null && $limitBytes > 0)
            ? min(100, round(($usedBytes / $limitBytes) * 100, 1))
            : null;

        return [
            'account' => $account,
            'wireguard' => $wireguard,
            'panelV2ray' => $panelV2ray,
            'clientCredentials' => $this->resolveClientCredentials($account),
            'purchaseInvoice' => $purchaseInvoice,
            'renewalInvoices' => $renewalInvoices,
            'recentTransactions' => $account->transactions,
            'renewalPrice' => $renewalPrice,
            'renewalCurrency' => $renewalCurrency,
            'canRenew' => $canRenew,
            'usage' => [
                'used_bytes' => $usedBytes,
                'limit_bytes' => $limitBytes,
                'remaining_bytes' => $remainingBytes,
                'percent' => $usagePercent,
            ],
            'isWireguard' => $account->service_type === ServiceType::Wireguard,
            'isPanelV2ray' => $account->service_type->isPanelV2ray(),
            'isPpp' => $account->service_type->accountCategory() === AccountCategory::Ppp,
            'ppp' => $this->pppConnectionDetails($account),
            'isAnyconnect' => $account->service_type->isAnyconnectFamily(),
            'anyconnect' => $this->anyconnectConnectionDetails($account),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function pppConnectionDetails(Account $account): array
    {
        if ($account->service_type->accountCategory() !== AccountCategory::Ppp) {
            return [];
        }

        $server = $account->server;
        $username = (string) ($account->remote_username ?? '');
        $password = (string) ($account->remote_password_enc ?? '');
        $usesIpsec = $this->pppProfileUsesIpsec($account);
        $ipsecSecret = '';
        $pppSecret = null;

        if ($server !== null && $server->isMikrotik()) {
            if ($username !== '') {
                try {
                    $pppSecret = $this->mikrotikService->getPppSecret($server, $username);

                    if ($pppSecret !== null) {
                        $username = (string) ($pppSecret['name'] ?? $username);
                        $password = (string) ($pppSecret['password'] ?? $password);
                    }
                } catch (Throwable) {
                    // fallback to DB values
                }
            }

            $usesIpsec = $usesIpsec || $this->l2tpIpsec->usesIpsec($server);
            $ipsecSecret = $this->l2tpIpsec->resolveSecretForDisplay($server);

            if ($ipsecSecret === '' && $pppSecret !== null) {
                $ipsecSecret = trim((string) ($pppSecret['ipsec-secret'] ?? ''));
            }
        }

        $endpointHost = $server !== null
            ? $server->vpnClientEndpointHost()
            : '';

        return [
            'service_label' => $account->service_type->label(),
            'profile_key' => $account->mikrotik_profile_key,
            'services' => $this->pppServiceList($account),
            'server_name' => $server?->name,
            'server_host' => $endpointHost,
            'username' => $username,
            'password' => $password,
            'uses_ipsec' => $usesIpsec || $ipsecSecret !== '',
            'ipsec_secret' => ($usesIpsec || $ipsecSecret !== '') ? $ipsecSecret : '',
            'l2tp_setup_guide_text' => $this->buildL2tpSetupGuideText(
                $endpointHost,
                $username,
                $password,
                ($usesIpsec || $ipsecSecret !== '') ? $ipsecSecret : '',
            ),
        ];
    }

    /**
     * The PPP protocols the account's server actually has enabled, with their
     * ports. Ports come from synced profile meta, with live detection / defaults
     * as fallback so the client page stays complete after a server change.
     *
     * @return list<array<string, mixed>>
     */
    protected function pppServiceList(Account $account): array
    {
        $server = $account->server;
        $profile = $this->resolvePppProfile($account);
        $ports = array_filter(
            (array) ($profile?->meta['ports'] ?? []),
            static fn ($port): bool => (int) $port > 0,
        );

        if ($ports === [] && $server !== null && $server->isMikrotik()) {
            try {
                $ports = $this->mikrotikService->detectEnabledServicePorts($server);
            } catch (Throwable) {
                $ports = [];
            }
        }

        $defaults = [
            'l2tp' => 1701,
            'ovpn' => 1194,
            'sstp' => 443,
            'pptp' => 1723,
        ];

        // Keep the page usable even when interface sync stored empty ports.
        if ($ports === []) {
            $ports = $defaults;
        }

        // Uploaded .ovpn means OpenVPN is offered even if port probe missed it.
        if (! isset($ports['ovpn']) && $server !== null && $server->hasOvpnProfile()) {
            $ports['ovpn'] = $defaults['ovpn'];
        }

        $definitions = [
            'l2tp' => ['label' => 'L2TP / IPsec', 'transport' => 'UDP', 'icon' => 'bx-mobile-alt', 'needs_ipsec' => true],
            'ovpn' => ['label' => 'OpenVPN', 'transport' => 'TCP', 'icon' => 'bx-shield-quarter', 'needs_file' => true],
            'sstp' => ['label' => 'SSTP', 'transport' => 'TCP', 'icon' => 'bx-lock-alt'],
            'pptp' => ['label' => 'PPTP', 'transport' => 'TCP', 'icon' => 'bx-plug'],
        ];

        $services = [];

        foreach ($definitions as $key => $definition) {
            $port = (int) ($ports[$key] ?? 0);

            if ($port <= 0) {
                continue;
            }

            $services[] = $definition + ['key' => $key, 'port' => $port];
        }

        return $services;
    }
    protected function buildL2tpSetupGuideText(
        string $address,
        string $accountName,
        string $password,
        string $secret,
    ): string {
        return str_replace(
            [':address', ':account', ':password', ':secret'],
            [$address, $accountName, $password, $secret],
            (string) __('accounts.l2tp_vpn_setup_template'),
        );
    }

    protected function pppProfileUsesIpsec(Account $account): bool
    {
        if ($account->service_type === ServiceType::L2tp) {
            return true;
        }

        $profile = $this->resolvePppProfile($account);

        return (bool) ($profile?->meta['use_encryption'] ?? false);
    }

    protected function resolvePppProfile(Account $account): ?ServerInterface
    {
        $server = $account->server;

        if ($server === null) {
            return null;
        }

        if ($account->mikrotik_profile_key) {
            $profile = ServerInterface::query()
                ->where('server_id', $server->id)
                ->where('remote_key', $account->mikrotik_profile_key)
                ->first();

            if ($profile !== null) {
                return $profile;
            }
        }

        try {
            return $this->mikrotikProfileService->resolveForPush(
                $server,
                $account->service_type,
                $account->mikrotik_profile_key,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */

    /**
     * @return array<string, mixed>
     */
    protected function anyconnectConnectionDetails(Account $account): array
    {
        if ($account->service_type->isOcserv()) {
            return app(OcservService::class)->connectionDetails($account);
        }

        if (! $account->service_type->isCiscoAnyconnect()) {
            return [];
        }

        return app(CiscoAnyconnectService::class)->connectionDetails($account);
    }

    protected function resolveClientCredentials(Account $account): ?array
    {
        $client = $account->clientUser;

        if ($client === null) {
            return null;
        }

        return [
            'display_label' => $account->display_label,
            'username' => $client->username,
            'email' => $client->email,
            'full_name' => $client->full_name,
            'password' => $account->client_portal_password_enc,
            'remote_username' => $account->remote_username,
            'remote_password' => $account->remote_password_enc,
        ];
    }
}
