<?php

namespace App\Services;

use App\Enums\AccountBillingContext;
use App\Enums\AccountCategory;
use App\Enums\AccountStatus;
use App\Enums\InvoiceType;
use App\Enums\ServiceType;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Exceptions\RemoteProvisionException;
use App\Jobs\RefreshSubscriptionCacheJob;
use App\Jobs\RemoveRemoteAccountJob;
use App\Support\AccountNameValidator;
use App\Support\RemoteAccountCleanupSnapshot;
use App\Models\Account;
use App\Models\AccountUsageLog;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\Server;
use App\Models\ServerInterface;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AccountService
{
    public function __construct(
        protected WalletService $walletService,
        protected InvoiceService $invoiceService,
        protected MikrotikService $mikrotikService,
        protected MikrotikProfileService $mikrotikProfileService,
        protected SanaeiService $sanaeiService,
        protected PasarguardService $pasarguardService,
        protected RemnawaveService $remnawaveService,
        protected CiscoAnyconnectService $ciscoAnyconnectService,
        protected OcservService $ocservService,
        protected ActivityLogService $activityLogService,
        protected UserHierarchyService $userHierarchyService,
        protected GlobalDiscountService $globalDiscountService,
        protected EndUserService $endUserService,
        protected UserPackageAssignmentService $userPackageAssignmentService,
        protected UserPackagePricingService $userPackagePricingService,
        protected TestPackageGuardService $testPackageGuardService,
        protected AccountRenewalPricingService $renewalPricingService,
        protected AccountBillingPackageService $billingPackageService,
        protected AgentFinancialPlanService $financialPlanService,
    ) {}

    /**
     * @param  array<string, mixed>  $clientData
     */
    public function createAccount(
        User $seller,
        Package $package,
        Server $server,
        PackageDuration $duration,
        array $clientData,
        AccountBillingContext $billingContext = AccountBillingContext::Staff,
        ?string $adminCustomCharge = null,
    ): Account {
        $chain = $this->userHierarchyService->resolveCommissionChain($seller);
        $ownerAgentId = $chain['owner_agent_id'];

        $duration->setRelation('package', $package);
        $dataGb = $this->resolvePurchaseDataGb($package, $clientData);
        $clientData['data_gb'] = $dataGb;

        $clientPortalBilling = $billingContext === AccountBillingContext::ClientPortal;
        $adminPriceOverride = $adminCustomCharge !== null;

        if ($adminPriceOverride) {
            $buyerCharge = money_string($adminCustomCharge);
            $chargeBreakdown = [
                'buyer_charge' => $buyerCharge,
                'plan_applied' => false,
                'plan_discount' => '0.00',
                'plan_wholesale' => '0.00',
                'slices' => [],
            ];
        } else {
            $buyerWholesale = $this->userPackagePricingService->buyerWholesaleTotal($seller, $duration, $dataGb);
            $chargeBreakdown = $this->financialPlanService->resolveCharge($seller, $buyerWholesale);
            $buyerCharge = $chargeBreakdown['buyer_charge'];
        }

        $economics = $clientPortalBilling
            ? null
            : $this->userPackagePricingService->resolvePurchaseEconomics(
                $seller,
                $duration,
                $buyerCharge,
                $dataGb,
                scaleCommissionsToCharge: $adminPriceOverride,
            );
        $purchaseResult = null;
        $account = null;
        $invoice = null;

        if (! $clientPortalBilling && bccomp($buyerCharge, '0', 2) > 0) {
            app(\App\Services\UserCurrencyService::class)->assertCanUseCurrency($seller, $package->moneyCurrency());
            $this->walletService->assertSufficientBalance($seller, $buyerCharge, $package->moneyCurrency());
        }

        $this->userPackageAssignmentService->assertUserHasPackage($seller, $package);

        $kycService = app(\App\Services\Kyc\KycService::class);
        $kycService->assertPackageRequiresKyc($package);
        $kycActor = ($clientData['kyc_actor'] ?? null) instanceof User
            ? $clientData['kyc_actor']
            : $seller;
        $kycVerification = null;
        if ($package->kyc_required) {
            $verificationId = (int) ($clientData['kyc_verification_id'] ?? 0);
            $kycVerification = $verificationId > 0
                ? \App\Models\AccountKycVerification::query()->find($verificationId)
                : null;
            $kycService->assertVerifiedForCreate($kycVerification, $package, $kycActor);
        }

        $skipPortalClient = (bool) ($clientData['skip_portal_client'] ?? false);
        $endUser = null;

        if (! $skipPortalClient) {
            $endUser = $this->endUserService->resolveForAccount($seller, $clientData);
            $this->testPackageGuardService->assertClientCanReceiveTest($endUser, $package, $duration);
        }

        try {
            return DB::transaction(function () use (
                $seller,
                $package,
                $server,
                $duration,
                $ownerAgentId,
                $clientData,
                $buyerCharge,
                $chargeBreakdown,
                $economics,
                $endUser,
                $clientPortalBilling,
                $adminPriceOverride,
                $kycService,
                $kycVerification,
                &$purchaseResult,
                &$account,
                &$invoice
            ) {
                $account = $this->provisionRemoteAccount($seller, $ownerAgentId, $package, $server, $duration, $clientData, $endUser?->id);

                if ($kycVerification instanceof \App\Models\AccountKycVerification) {
                    $kycService->attachToAccount($kycVerification, $account);
                }

                if (! $clientPortalBilling) {
                    $purchaseResult = $this->walletService->processTieredPurchase(
                        $economics,
                        [
                            'description' => 'New account purchase',
                            'currency' => $package->moneyCurrency()->value,
                        ]
                    );

                    if (! $adminPriceOverride) {
                        $this->financialPlanService->applyUsages(
                            $chargeBreakdown,
                            $seller,
                            $account->id,
                            $purchaseResult['buyer_transaction']?->id,
                            TransactionType::Purchase,
                        );
                    }

                    $this->linkTieredPurchaseTransactions($purchaseResult, $account->id);
                    $invoice = $this->invoiceService->createInvoice($account, \App\Enums\InvoiceType::NewAccount, $buyerCharge);
                    $this->linkTieredPurchaseTransactionsToInvoice($purchaseResult, $invoice->id);

                    $this->activityLogService->log(
                        $seller,
                        'account.created',
                        $account,
                        ['package_id' => $package->id, 'invoice_id' => $invoice->id]
                    );
                } else {
                    $this->activityLogService->log(
                        $seller,
                        'account.created',
                        $account,
                        ['package_id' => $package->id, 'billing' => AccountBillingContext::ClientPortal->value]
                    );
                }

                if (! empty($clientData['store_portal_password'])) {
                    $account->update([
                        'client_portal_password_enc' => (string) $clientData['store_portal_password'],
                    ]);
                }

                // Cache the subscription body out-of-band so /sub/{token} can answer
                // without touching the panel. Queued (never inline) because Sanaei
                // needs a live POST /setting/all just to learn its subscription URL;
                // the job row commits with this transaction, so a rolled-back
                // purchase leaves no job behind.
                RefreshSubscriptionCacheJob::dispatchFor($account);

                return $account->fresh();
            });
        } catch (Throwable $exception) {
            if ($this->purchaseTransactionsPersisted($purchaseResult)) {
                $this->rollbackPurchase($seller, $purchaseResult, $account?->id);
            }

            if ($account !== null) {
                $this->safeRemoteCleanup($account, $server);
                $account->delete();
            }

            Log::error('Account creation failed', [
                'seller_id' => $seller->id,
                'package_id' => $package->id,
                'server_id' => $server->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function renewAccount(
        Account $account,
        ?PackageDuration $duration = null,
        AccountBillingContext $billingContext = AccountBillingContext::Staff,
        ?User $actor = null,
        ?float $renewalGbOverride = null,
        string $renewalMode = 'same',
    ): Account {
        $account->loadMissing(['ownerSeller.parent', 'package', 'packageDuration', 'server']);
        $storedPackage = $account->package;

        if ($storedPackage !== null) {
            app(PackageCategoryService::class)->assertPackageAvailableForRenewal($storedPackage);
        }

        $account = $this->billingPackageService->syncBillingPackage($account, $duration);
        $package = $account->package;

        if ($package === null) {
            throw new \InvalidArgumentException(__('packages.package_required_for_renewal'));
        }

        app(PackageCategoryService::class)->assertPackageAvailableForRenewal($package);

        if ($duration === null) {
            $duration = $account->packageDuration;
        }

        if ($duration !== null) {
            $duration = $this->billingPackageService->assertDurationBelongsToBillingPackage($account, $duration);
        }

        if ($duration === null) {
            $duration = $package->durations()->where('is_enabled', true)->orderBy('sort_order')->first();
        }

        if ($duration === null || (int) $duration->package_id !== (int) $package->id) {
            throw new \InvalidArgumentException(__('packages.duration_not_available'));
        }

        $duration->setRelation('package', $package);

        $actor ??= $account->ownerSeller;

        if ($actor === null) {
            throw new \InvalidArgumentException(__('accounts.refund_owner_missing'));
        }

        $buyer = $billingContext === AccountBillingContext::ClientPortal
            ? $account->ownerSeller
            : $this->renewalPricingService->resolveRenewalBuyer($actor, $account);

        if ($buyer === null) {
            throw new \InvalidArgumentException(__('accounts.refund_owner_missing'));
        }

        $billingPackage = $this->billingPackageService->resolveBillingPackage($account);

        $renewalGb = $renewalGbOverride ?? $this->renewalPricingService->billableDataGb($account);

        $clientPortalBilling = $billingContext === AccountBillingContext::ClientPortal;

        if ($clientPortalBilling) {
            $buyerWholesale = $this->userPackagePricingService->lineTotal($buyer, $duration, $renewalGb, forRenewal: true);
            $chargeBreakdown = $this->financialPlanService->resolveCharge($buyer, $buyerWholesale);
            $buyerCharge = $chargeBreakdown['buyer_charge'];
            $economics = null;
        } else {
            $billing = $this->renewalPricingService->billingForRenewal(
                $buyer,
                $account,
                $duration,
                $renewalMode,
                $renewalGbOverride,
            );
            $buyerWholesale = $billing['quote']['wholesale_total'];
            $buyerCharge = $billing['quote']['charged_total'];
            $chargeBreakdown = $billing['charge_breakdown'];
            $economics = $billing['economics'];

            if ($billingPackage->isElastic() && $billing['quote']['data_gb'] !== null) {
                $renewalGb = round((float) $billing['quote']['data_gb'], 2);
            }
        }

        if (! $clientPortalBilling) {
            app(\App\Services\UserCurrencyService::class)->assertCanUseCurrency($buyer, $billingPackage->moneyCurrency());
            $this->walletService->assertSufficientBalance($buyer, $buyerCharge, $billingPackage->moneyCurrency());
        }

        $purchaseResult = null;

        try {
            return DB::transaction(function () use (
                $account,
                $actor,
                $buyer,
                $package,
                $billingPackage,
                $duration,
                $renewalGb,
                $buyerCharge,
                $chargeBreakdown,
                $economics,
                $clientPortalBilling,
                &$purchaseResult,
                $renewalMode,
            ) {
                $purchasedBeforeGb = $billingPackage->isElastic()
                    ? $this->resolvePurchasedDataGb($account)
                    : null;
                $limitBeforeBytes = $account->data_limit_bytes;

                $preserveUsage = in_array($renewalMode, ['add_volume', 'upgrade_volume'], true);
                $resetTraffic = ! $preserveUsage;

                if ($preserveUsage && $this->usesPanelTrafficAccounting($account)) {
                    $this->syncUsedBytesFromRemote($account);
                    $account->refresh();
                }

                if (! $preserveUsage) {
                    $account->data_used_bytes = 0;
                }

                // Renew from the account's own end date when it is still valid, so
                // renewing early carries the unused days over instead of losing them.
                // Already expired (or no expiry yet) → start from now.
                $renewalBase = $account->expiry_at !== null && $account->expiry_at->isFuture()
                    ? $account->expiry_at->copy()
                    : now();

                $account->expiry_at = $duration->expiryFrom($renewalBase);
                $account->package_duration_id = $duration->id;
                $account->status = AccountStatus::Active;

                $this->applyElasticRenewalVolume($account, $billingPackage, $renewalMode, $renewalGb);

                $account->save();

                $this->renewRemoteAccount($account, $resetTraffic, forceEnable: true);
                $this->reconcilePanelQuotaAfterRenewal($account->fresh(), $resetTraffic);

                if (! $clientPortalBilling) {
                    $purchaseResult = $this->walletService->processTieredPurchase(
                        $economics,
                        [
                            'type' => TransactionType::Renewal,
                            'description' => 'Account renewal',
                            'related_account_id' => $account->id,
                            'currency' => $billingPackage->moneyCurrency()->value,
                        ]
                    );

                    $this->financialPlanService->applyUsages(
                        $chargeBreakdown,
                        $buyer,
                        $account->id,
                        $purchaseResult['buyer_transaction']?->id,
                        TransactionType::Renewal,
                    );

                    $this->linkTieredPurchaseTransactions($purchaseResult, $account->id);
                    $renewalInvoice = $this->invoiceService->createInvoice($account, \App\Enums\InvoiceType::Renewal, $buyerCharge);
                    $this->linkTieredPurchaseTransactionsToInvoice($purchaseResult, $renewalInvoice->id);
                }

                $this->activityLogService->log($actor, 'account.renewed', $account, array_filter([
                    'billing' => $clientPortalBilling ? AccountBillingContext::ClientPortal->value : null,
                    'renewal_mode' => $renewalMode,
                    'renewal_gb' => $renewalGb,
                    'purchased_before_gb' => $purchasedBeforeGb,
                    'data_limit_before_bytes' => $limitBeforeBytes,
                ]));

                // Renewal can change inbounds/quota on the panel, so the cached
                // subscription body is refreshed too (same queued path as creation).
                RefreshSubscriptionCacheJob::dispatchFor($account);

                return $account->fresh();
            });
        } catch (Throwable $exception) {
            if ($this->purchaseTransactionsPersisted($purchaseResult)) {
                $this->rollbackPurchase($buyer, $purchaseResult, $account->id);
            }

            throw $exception;
        }
    }

    /**
     * Disable the account on the remote panel/server only (no local status change).
     */
    public function deactivateRemoteForRefund(Account $account): void
    {
        $account->loadMissing('server');

        if ($account->server === null) {
            return;
        }

        $this->disableRemoteAccount($account);
    }

    /**
     * Re-enable the account on the remote panel/server only (no local status change).
     */
    public function activateRemoteAfterRefund(Account $account): void
    {
        $account->loadMissing('server');

        if ($account->server === null) {
            return;
        }

        $this->enableRemoteAccount($account);
    }

    /**
     * Admin adjusts account expiry. DB is authoritative; remote panel is synced via push.
     */
    public function updateExpiryByAdmin(
        Account $account,
        ?\Illuminate\Support\Carbon $newExpiry,
        User $admin,
        bool $syncRemote = true,
        bool $logActivity = true,
    ): Account {
        $account->loadMissing(['server', 'package', 'packageDuration']);
        $previousExpiry = $account->expiry_at?->copy();

        if ($previousExpiry !== null && $newExpiry !== null && $previousExpiry->equalTo($newExpiry)) {
            return $account;
        }

        if ($previousExpiry === null && $newExpiry === null) {
            return $account;
        }

        $daysDelta = $this->expiryAdjustmentDays($previousExpiry, $newExpiry);

        $payload = ['expiry_at' => $newExpiry];

        // Bulk gifts must stay DB-fast: reactivate expired accounts locally and
        // let the caller push to remote panels after the HTTP response.
        if (
            ! $syncRemote
            && $newExpiry !== null
            && $newExpiry->isFuture()
            && $account->status === AccountStatus::Expired
        ) {
            $payload['status'] = AccountStatus::Active;
        }

        $account->update($payload);
        $account = $account->fresh();

        if ($syncRemote && $account->server !== null) {
            $this->pushAccountToServer($account, false);
        }

        if (
            $syncRemote
            && $newExpiry !== null
            && $newExpiry->isFuture()
            && $account->status === AccountStatus::Expired
        ) {
            $account = $this->enableAccount($account);
        }

        if ($logActivity) {
            $this->activityLogService->log($admin, 'account.expiry_adjusted', $account, [
                'previous_expiry' => $previousExpiry?->toIso8601String(),
                'new_expiry' => $newExpiry?->toIso8601String(),
                'days_delta' => $daysDelta,
            ]);
        }

        return $account->fresh();
    }

    protected function expiryAdjustmentDays(?\Illuminate\Support\Carbon $previous, ?\Illuminate\Support\Carbon $next): ?int
    {
        if ($next === null) {
            return null;
        }

        $base = $previous ?? now();

        return (int) $base->copy()->startOfDay()->diffInDays($next->copy()->startOfDay(), false);
    }

    public function disableAccount(Account $account, bool $exhausted = false): Account
    {
        $account->loadMissing('server');
        $status = $exhausted ? AccountStatus::Exhausted : AccountStatus::Disabled;

        $this->disableRemoteAccount($account);

        $account->update(['status' => $status]);

        $this->activityLogService->log(null, 'account.disabled', $account, [
            'reason' => $exhausted ? 'quota_exhausted' : 'manual',
        ]);

        return $account->fresh();
    }

    public function expireAccount(Account $account): Account
    {
        $account->loadMissing('server');

        try {
            $this->disableRemoteAccount($account);
        } catch (Throwable $exception) {
            Log::warning('Failed to disable remote account on expiry', [
                'account_id' => $account->id,
                'remote_username' => $account->remote_username,
                'error' => $exception->getMessage(),
            ]);
        }

        $account->update(['status' => AccountStatus::Expired]);

        $this->activityLogService->log(null, 'account.expired', $account);

        return $account->fresh();
    }

    /**
     * Delete from panel DB immediately and return before touching the remote server.
     * Remote (MikroTik/panel) cleanup is deferred until *after* the HTTP response has
     * already been sent, so a slow/unreachable router can never turn a successful
     * delete into an HTTP 500 (PHP-FPM/nginx timeout) or block the panel UI.
     *
     * @return string|null Always null immediately; remote cleanup failures are only logged.
     */
    public function deleteAccount(Account $account, ?User $actor = null): ?string
    {
        $serviceTypeValue = 'unknown';
        $snapshot = null;

        try {
            $account->loadMissing('server');
            $serviceTypeValue = $account->service_type?->value ?? 'unknown';

            if ($this->accountNeedsRemoteCleanup($account)) {
                $snapshot = RemoteAccountCleanupSnapshot::fromAccount($account);
            }
        } catch (Throwable $exception) {
            Log::warning('Failed to build remote cleanup snapshot before delete', [
                'account_id' => $account->id,
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $this->activityLogService->log($actor, 'account.deleted', $account, [
                'remote_username' => $account->remote_username,
                'service_type' => $serviceTypeValue,
                'owner_seller_id' => $account->owner_seller_id,
                'remote_cleanup_snapshot' => $snapshot !== null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Activity log failed during account delete', [
                'account_id' => $account->id,
                'error' => $exception->getMessage(),
            ]);
        }

        // The DB delete is the source of truth for the panel; nothing after this
        // point may ever bubble up and turn a successful delete into an HTTP 500.
        $account->delete();

        if ($snapshot === null) {
            return null;
        }

        // Deferred: runs after the response is flushed to the browser (fastcgi_finish_request),
        // so a slow or unreachable MikroTik can never cause a request timeout / HTTP 500 here.
        Bus::dispatchAfterResponse(function () use ($snapshot): void {
            try {
                @set_time_limit(150);
                $this->executeRemoteCleanupFromSnapshot($snapshot);
            } catch (Throwable $exception) {
                Log::warning('Remote account removal failed after panel delete', [
                    'account_id' => $snapshot->accountId,
                    'remote_username' => $snapshot->remoteUsername,
                    'service_type' => $snapshot->serviceType ?? 'unknown',
                    'error' => $exception->getMessage(),
                ]);

                try {
                    RemoveRemoteAccountJob::dispatch($snapshot);
                } catch (Throwable $dispatchException) {
                    Log::warning('Failed to queue retry for remote account removal', [
                        'account_id' => $snapshot->accountId,
                        'error' => $dispatchException->getMessage(),
                    ]);
                }
            }
        });

        return null;
    }

    public function cleanupRemoteForDeletedAccount(int $accountId): void
    {
        $account = Account::withTrashed()->with('server')->find($accountId);

        if ($account === null || ! $account->trashed()) {
            return;
        }

        try {
            $this->executeRemoteCleanupFromSnapshot(RemoteAccountCleanupSnapshot::fromAccount($account));
        } catch (Throwable $exception) {
            Log::warning('Remote account removal failed after panel delete', [
                'account_id' => $account->id,
                'remote_username' => $account->remote_username,
                'service_type' => $account->service_type?->value ?? 'unknown',
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function executeRemoteCleanupFromSnapshot(RemoteAccountCleanupSnapshot $snapshot): void
    {
        if ($snapshot->serverId <= 0) {
            return;
        }

        $server = Server::query()->find($snapshot->serverId);

        if ($server === null) {
            Log::warning('Remote account cleanup skipped: server not found', [
                'account_id' => $snapshot->accountId,
                'server_id' => $snapshot->serverId,
            ]);

            return;
        }

        $serviceType = $snapshot->serviceType !== null
            ? ServiceType::tryFrom($snapshot->serviceType)
            : null;

        if ($server->isPasarguard() || ($serviceType?->isPasarguard() ?? false) || filled($snapshot->pasarguardUserId)) {
            if (filled($snapshot->remoteUsername)) {
                $this->pasarguardService->removePanelUser($server, $snapshot->remoteUsername);
            }

            return;
        }

        if ($server->isOcserv() || ($serviceType?->isOcserv() ?? false)) {
            $username = (string) ($snapshot->remoteUsername ?? '');
            if ($username !== '') {
                $this->ocservService->removeVpnUser($server, $username);
            }

            return;
        }

        if ($server->isCiscoAnyconnect() || ($serviceType?->isCiscoAnyconnect() ?? false) || filled($snapshot->ciscoAsaUsername ?? null)) {
            $username = (string) ($snapshot->ciscoAsaUsername ?? $snapshot->remoteUsername ?? '');
            if ($username !== '') {
                $this->ciscoAnyconnectService->removeVpnUser($server, $username);
            }

            return;
        }

        if ($server->isRemnawave() || ($serviceType?->isRemnawave() ?? false) || filled($snapshot->remnawaveUuid)) {
            if (filled($snapshot->remnawaveUuid) || filled($snapshot->remoteUsername)) {
                $this->remnawaveService->removePanelUser(
                    $server,
                    (string) ($snapshot->remnawaveUuid ?? ''),
                    $snapshot->remoteUsername,
                );
            }

            return;
        }

        if (($serviceType?->isSanaei() ?? false) && filled($snapshot->sanaeiClientUuid)) {
            $email = $snapshot->clientEmail ?? $snapshot->remoteUsername.'@shahpanel.local';
            // چرا: یک اکانت ممکن است روی چند inbound کلاینت داشته باشد (هر کدام با
            // ایمیل مخصوص خودش)؛ حذف فقط یکی از آن‌ها بقیه را روی پنل جا می‌گذاشت.
            $this->sanaeiService->deleteAccountClients(
                $server,
                $email,
                $snapshot->sanaeiClientUuid,
            );

            return;
        }

        if (filled($snapshot->wireguardPublicKey) && ($serviceType === null || $serviceType === ServiceType::Wireguard)) {
            $this->mikrotikService->removePeer($server, $snapshot->wireguardPublicKey);

            return;
        }

        if (filled($snapshot->remoteUsername) && ($serviceType === null || $serviceType->isMikrotik())) {
            $this->mikrotikService->deleteUser($server, $snapshot->remoteUsername);

            Log::info('MikroTik PPP secret removed after panel delete', [
                'account_id' => $snapshot->accountId,
                'server_id' => $snapshot->serverId,
                'remote_username' => $snapshot->remoteUsername,
            ]);
        }
    }

    protected function accountNeedsRemoteCleanup(Account $account): bool
    {
        if ($account->server === null) {
            return false;
        }

        if ($account->wireguard_public_key !== null && $account->wireguard_public_key !== '') {
            return true;
        }

        if (filled($account->remote_username)) {
            return true;
        }

        return filled($account->pasarguard_user_id)
            || filled($account->remnawave_uuid)
            || filled($account->cisco_asa_username)
            || filled($account->sanaei_client_uuid);
    }

    public function enableAccount(Account $account): Account
    {
        $account->loadMissing('server');

        if ($account->isExpired()) {
            throw new \InvalidArgumentException('Cannot enable an expired account.');
        }

        if ($account->isQuotaExhausted()) {
            throw new \InvalidArgumentException('Cannot enable an account with exhausted quota.');
        }

        $this->enableRemoteAccount($account);
        $account->update(['status' => AccountStatus::Active]);

        $this->activityLogService->log(null, 'account.enabled', $account);

        return $account->fresh();
    }

    /**
     * Push account state from DB to remote server (migration / repair).
     * data_limit_bytes, data_used_bytes, expiry_at and status in DB are authoritative.
     *
     * @return array{action: string, message: string}
     */
    public function pushAccountToServer(Account $account, bool $onlyMissing = false): array
    {
        $account->loadMissing(['server', 'package']);
        $server = $account->server;

        if ($server === null) {
            throw new \InvalidArgumentException(__('services.account_not_attached_to_server'));
        }

        if (! $account->isUnlimited() && $account->data_limit_bytes === null) {
            throw new \InvalidArgumentException(__('services.account_volume_requires_data_limit'));
        }

        if ($server->isPasarguard() || $account->service_type->isPasarguard() || $account->pasarguard_user_id) {
            return $this->pushPasarguardAccount($account, $onlyMissing);
        }

        if ($server->isOcserv() || $account->service_type->isOcserv()) {
            return $this->pushOcservAccount($account, $onlyMissing);
        }

        if ($server->isCiscoAnyconnect() || $account->service_type->isCiscoAnyconnect() || $account->cisco_asa_username) {
            return $this->pushCiscoAnyconnectAccount($account, $onlyMissing);
        }

        if ($server->isRemnawave() || $account->service_type->isRemnawave() || $account->remnawave_uuid) {
            return $this->pushRemnawaveAccount($account, $onlyMissing);
        }

        if ($account->service_type->isSanaei() || $server->isSanaei()) {
            return $this->pushSanaeiAccount($account, $onlyMissing);
        }

        if ($server->isMikrotik() && $account->wireguard_public_key) {
            return $this->pushWireguardAccount($account, $onlyMissing);
        }

        return match ($account->service_type) {
            ServiceType::Wireguard => $this->pushWireguardAccount($account, $onlyMissing),
            default => $this->pushMikrotikSecretAccount($account, $onlyMissing),
        };
    }

    /**
     * @return array{action: string, message: string}
     */
    /**
     * 3x-ui client counters: `up` = download, `down` = upload (panel API convention).
     *
     * @return array{up: int, down: int}
     */
    protected function sanaeiPanelTrafficFromAccount(Account $account): array
    {
        $used = max(0, (int) $account->data_used_bytes);
        $log = $account->usageLogs()->orderByDesc('recorded_at')->first();

        if ($log !== null) {
            $download = max(0, (int) ($log->rx_snapshot ?? 0));
            $upload = max(0, (int) ($log->tx_snapshot ?? 0));
            $snapshotSum = $download + $upload;

            if ($snapshotSum > 0 && $used > 0 && $snapshotSum !== $used) {
                $download = (int) round($used * ($download / $snapshotSum));
                $upload = $used - $download;
            } elseif ($snapshotSum > 0) {
                return ['up' => $download, 'down' => $upload];
            }
        }

        return ['up' => $used, 'down' => 0];
    }

    /**
     * @return array{action: string, message: string}
     */
    protected function pushSanaeiAccount(Account $account, bool $onlyMissing): array
    {
        $server = $account->server;
        $email = $account->client_email ?? $account->remote_username.'@shahpanel.local';
        $uuid = $account->sanaei_client_uuid;
        $legacyInboundId = (int) ($account->sanaei_inbound_id ?? 0) ?: null;
        // چرا: inboundهای هدف روی پکیج تعیین می‌شوند؛ خالی بودن آن یعنی
        // «همه inboundهای فعال» تا پکیج‌های قدیمی دقیقاً مثل قبل کار کنند.
        $account->loadMissing('package');
        $targetInboundIds = $this->sanaeiService->resolveProvisionInboundIds(
            $server,
            $account->package?->sanaeiInboundIds() ?? []
        );
        $primaryInboundId = $legacyInboundId ?: $targetInboundIds[0];
        $totalGB = $account->isUnlimited() ? null : $account->data_limit_bytes / (1024 ** 3);
        $expiryMs = $account->expiry_at ? (int) ($account->expiry_at->getTimestamp() * 1000) : 0;
        $panelTraffic = $this->sanaeiPanelTrafficFromAccount($account);
        $shouldEnable = $account->status === AccountStatus::Active
            && ! $account->isExpired()
            && ! $account->isQuotaExhausted();

        $found = $this->sanaeiService->findClientByEmail($server, $email);
        $existingClient = $found !== null
            ? (is_array($found['client'] ?? null) ? $found['client'] : null)
            : null;

        if ($existingClient === null && $uuid) {
            $existingClient = $this->sanaeiService->resolvePanelClient(
                $server,
                $email,
                (string) $uuid,
                $legacyInboundId
            );
        }

        if ($existingClient !== null && $onlyMissing) {
            return [
                'action' => 'skipped',
                'message' => __('services.sanaei_client_already_existed', ['email' => $email]),
            ];
        }

        if ($existingClient !== null) {
            $clientUuid = $uuid ?? (string) ($existingClient['id'] ?? '');
            if ($clientUuid !== '' && ! $account->sanaei_client_uuid) {
                $account->update([
                    'sanaei_client_uuid' => $clientUuid,
                    'sanaei_sub_id' => $account->sanaei_sub_id ?: $this->sanaeiService->extractSubId($existingClient),
                ]);
            }

            $this->sanaeiService->updateAccountClients($server, $email, $clientUuid, [
                'totalGB' => $totalGB,
                'expiryTime' => $expiryMs,
                'enable' => $shouldEnable,
                'up' => $panelTraffic['up'],
                'down' => $panelTraffic['down'],
                'subId' => $account->sanaei_sub_id ?: null,
            ], $primaryInboundId);

            return [
                'action' => 'updated',
                'message' => __('services.sanaei_client_updated', ['email' => $email]),
            ];
        }

        $uuid = (string) ($uuid ?: Str::uuid());
        $createdClient = $this->sanaeiService->createClient(
            $server,
            $email,
            $uuid,
            totalGB: $totalGB,
            expiryTime: $expiryMs,
            up: $panelTraffic['up'],
            down: $panelTraffic['down'],
            subId: $account->sanaei_sub_id,
            inboundIds: $targetInboundIds,
        );

        if (! $shouldEnable) {
            $this->sanaeiService->disableAccountClients($server, $email, $uuid, $targetInboundIds[0]);
        }

        $account->update([
            'sanaei_client_uuid' => $uuid,
            'sanaei_sub_id' => $this->sanaeiService->extractSubId($createdClient),
            // چرا: inbound اصلی همان جایی است که ایمیل پایه روی آن نشسته؛ تا
            // پیش از این همیشه null ذخیره می‌شد و همه عملیات بعدی کورکورانه
            // همه inboundها را می‌گشتند.
            'sanaei_inbound_id' => $targetInboundIds[0],
            'client_email' => $email,
        ]);

        return [
            'action' => 'created',
            'message' => __('services.sanaei_client_created', ['email' => $email]),
        ];
    }

    /**
     * @return array{action: string, message: string}
     */
    protected function pushPasarguardAccount(Account $account, bool $onlyMissing): array
    {
        $account->loadMissing(['server', 'package', 'packageDuration']);
        $server = $account->server;
        $username = $account->remote_username;
        $package = $account->package;

        if ($package === null) {
            throw new \InvalidArgumentException(__('services.account_pasarguard_no_package'));
        }

        try {
            $existing = $this->pasarguardService->getUser($server, $username);
        } catch (\Throwable) {
            $existing = null;
        }

        if ($existing !== null && $onlyMissing) {
            return [
                'action' => 'skipped',
                'message' => __('services.pasarguard_user_already_existed', ['username' => $username]),
            ];
        }

        $duration = $this->resolvePasarguardDuration($account);
        $shouldEnable = $account->status === AccountStatus::Active
            && ! $account->isExpired()
            && ! $account->isQuotaExhausted();

        if ($existing === null) {
            $remote = $this->pasarguardService->createPanelUser(
                $server,
                $package,
                $duration,
                $username,
                $account->data_limit_bytes,
                $account->expiry_at,
            );
            $account->update([
                'pasarguard_user_id' => (int) ($remote['id'] ?? $account->pasarguard_user_id),
                'pasarguard_subscription_url' => (string) ($remote['subscription_url'] ?? $account->pasarguard_subscription_url),
            ]);

            return [
                'action' => 'created',
                'message' => __('services.pasarguard_user_created', ['username' => $username]),
            ];
        }

        $remote = $this->pasarguardService->modifyPanelUser(
            $server,
            $username,
            $package,
            $duration,
            $account->data_limit_bytes,
            $account->expiry_at,
            $shouldEnable,
        );

        $account->update([
            'pasarguard_user_id' => (int) ($remote['id'] ?? $account->pasarguard_user_id),
            'pasarguard_subscription_url' => (string) ($remote['subscription_url'] ?? $account->pasarguard_subscription_url),
        ]);

        return [
            'action' => 'updated',
            'message' => __('services.pasarguard_user_updated', ['username' => $username]),
        ];
    }

    protected function renewPasarguardAccount(Account $account, bool $resetTraffic = true, bool $forceEnable = false): void
    {
        $account->loadMissing(['server', 'package', 'packageDuration']);

        if ($resetTraffic) {
            $this->resetPasarguardTrafficOrFail($account);
            $account->update(['data_used_bytes' => 0]);
        }

        $shouldEnable = $this->resolvePanelShouldEnable($account, $forceEnable);

        $remote = $this->pasarguardService->modifyPanelUser(
            $account->server,
            $account->remote_username,
            $account->package,
            $this->resolvePasarguardDuration($account),
            $account->data_limit_bytes,
            $account->expiry_at,
            $shouldEnable,
        );

        if (! empty($remote['subscription_url'])) {
            $account->update([
                'pasarguard_subscription_url' => (string) $remote['subscription_url'],
                'pasarguard_user_id' => (int) ($remote['id'] ?? $account->pasarguard_user_id),
            ]);
        }
    }

    protected function resolvePasarguardDuration(Account $account): PackageDuration
    {
        if ($account->packageDuration !== null) {
            return $account->packageDuration;
        }

        $account->loadMissing('package');

        if ($account->package === null) {
            throw new \InvalidArgumentException(
                __('services.account_id_no_package', ['id' => $account->id, 'username' => $account->remote_username])
            );
        }

        $duration = $account->package
            ->durations()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->first();

        if ($duration === null) {
            throw new \InvalidArgumentException(
                __('services.package_no_active_duration', ['name' => $account->package->name])
            );
        }

        return $duration;
    }

    protected function resolveRemnawaveDuration(Account $account): PackageDuration
    {
        return $this->resolvePasarguardDuration($account);
    }

    /**
     * Resolve Remnawave user identity (v2 UUID or v3 numeric id).
     * Lazy-migrates stored UUID → numeric id after a panel upgrade to v3.
     */
    protected function resolveRemnawaveUuid(Account $account): ?string
    {
        $stored = trim((string) ($account->remnawave_uuid ?? ''));

        if ($stored !== '') {
            // After Remnawave 3.x upgrade, old UUID values no longer work in paths.
            if (\App\Services\Remnawave\RemnawaveUserIdentity::isUuid($stored) && filled($account->remote_username)) {
                $remote = $this->remnawaveService->getUser($account->server, (string) $account->remote_username);
                $migrated = $remote !== null
                    ? \App\Services\Remnawave\RemnawaveUserIdentity::fromRemoteUser($remote)
                    : null;

                if ($migrated !== null && $migrated !== $stored) {
                    $account->update([
                        'remnawave_uuid' => $migrated,
                        'remnawave_subscription_url' => (string) ($remote['subscriptionUrl'] ?? $account->remnawave_subscription_url),
                    ]);

                    return $migrated;
                }

                // Still on v2 (or user missing): keep stored UUID.
                if ($migrated !== null) {
                    return $migrated;
                }
            }

            return $stored;
        }

        $remote = $this->remnawaveService->getUser($account->server, (string) $account->remote_username);
        $identity = $remote !== null
            ? \App\Services\Remnawave\RemnawaveUserIdentity::fromRemoteUser($remote)
            : null;

        if ($identity !== null) {
            $account->update([
                'remnawave_uuid' => $identity,
                'remnawave_subscription_url' => (string) ($remote['subscriptionUrl'] ?? $account->remnawave_subscription_url),
            ]);

            return $identity;
        }

        return null;
    }

    protected function renewRemnawaveAccount(Account $account, bool $resetTraffic = true, bool $forceEnable = false): void
    {
        $account->loadMissing(['server', 'package', 'packageDuration']);
        $uuid = $this->resolveRemnawaveUuid($account);

        if ($uuid === null) {
            throw new RemoteProvisionException(__('services.remnawave_user_not_found_unknown_id'));
        }

        if ($resetTraffic) {
            $this->resetRemnawaveTrafficOrFail($account, $uuid);
            $account->update(['data_used_bytes' => 0]);
        }

        $shouldEnable = $this->resolvePanelShouldEnable($account, $forceEnable);
        $usedBytes = $resetTraffic ? 0 : max(0, (int) $account->data_used_bytes);

        $remote = $this->remnawaveService->modifyPanelUser(
            $account->server,
            $uuid,
            $account->package,
            $this->resolveRemnawaveDuration($account),
            $account->data_limit_bytes,
            $account->expiry_at,
            $shouldEnable,
            $usedBytes > 0 ? $usedBytes : null,
            (string) $account->remote_username,
        );

        $identity = \App\Services\Remnawave\RemnawaveUserIdentity::fromRemoteUser($remote) ?? $uuid;
        $account->update(array_filter([
            'remnawave_subscription_url' => ! empty($remote['subscriptionUrl'])
                ? (string) $remote['subscriptionUrl']
                : null,
            'remnawave_uuid' => $identity,
        ], fn ($v) => $v !== null));
    }

    /**
     * @return array{action: string, message: string}
     */
    protected function pushRemnawaveAccount(Account $account, bool $onlyMissing): array
    {
        $account->loadMissing(['server', 'package', 'packageDuration']);
        $server = $account->server;
        $username = $account->remote_username;
        $package = $account->package;

        if ($package === null) {
            throw new \InvalidArgumentException(__('services.account_remnawave_no_package'));
        }

        $existing = $this->remnawaveService->getUser($server, $username);

        if ($existing !== null && $onlyMissing) {
            return [
                'action' => 'skipped',
                'message' => __('services.remnawave_user_already_existed', ['username' => $username]),
            ];
        }

        $duration = $this->resolveRemnawaveDuration($account);
        $shouldEnable = $account->status === AccountStatus::Active
            && ! $account->isExpired()
            && ! $account->isQuotaExhausted();

        if ($existing === null) {
            $remote = $this->remnawaveService->createPanelUser(
                $server,
                $package,
                $duration,
                $username,
                $account->data_limit_bytes,
                $account->expiry_at,
            );
            $account->update([
                'remnawave_uuid' => \App\Services\Remnawave\RemnawaveUserIdentity::fromRemoteUser($remote)
                    ?? ((string) ($account->remnawave_uuid ?? '') ?: null),
                'remnawave_subscription_url' => (string) ($remote['subscriptionUrl'] ?? $account->remnawave_subscription_url),
            ]);

            return [
                'action' => 'created',
                'message' => __('services.remnawave_user_created', ['username' => $username]),
            ];
        }

        $uuid = \App\Services\Remnawave\RemnawaveUserIdentity::fromRemoteUser($existing)
            ?? trim((string) ($account->remnawave_uuid ?? ''));
        if ($uuid === '') {
            throw new RemoteProvisionException(__('services.remnawave_user_not_found_unknown_id'));
        }

        $remote = $this->remnawaveService->modifyPanelUser(
            $server,
            $uuid,
            $package,
            $duration,
            $account->data_limit_bytes,
            $account->expiry_at,
            $shouldEnable,
            null,
            $username,
        );

        $account->update([
            'remnawave_uuid' => \App\Services\Remnawave\RemnawaveUserIdentity::fromRemoteUser($remote) ?? $uuid,
            'remnawave_subscription_url' => (string) ($remote['subscriptionUrl'] ?? $account->remnawave_subscription_url),
        ]);

        return [
            'action' => 'updated',
            'message' => __('services.remnawave_user_updated', ['username' => $username]),
        ];
    }

    /**
     * @return array{action: string, message: string}
     */
    protected function pushMikrotikSecretAccount(Account $account, bool $onlyMissing): array
    {
        $server = $account->server;
        $username = $account->remote_username;
        $password = $account->remote_password_enc ?? $this->generateRemotePassword($account->service_type);
        $exists = $this->mikrotikService->pppSecretExists($server, $username);
        $shouldEnable = $account->status === AccountStatus::Active
            && ! $account->isExpired()
            && ! $account->isQuotaExhausted();

        if ($exists && $onlyMissing) {
            return [
                'action' => 'skipped',
                'message' => __('services.mikrotik_user_already_existed', ['username' => $username]),
            ];
        }

        $profile = $this->mikrotikProfileService->resolveForPush(
            $server,
            $account->service_type,
            $account->mikrotik_profile_key,
            respectProfileKeyWhenFull: $exists,
        );
        $pppProfile = $this->mikrotikProfileService->pppProfileName($profile);

        if (! $exists) {
            $service = $this->mikrotikProfileService->pppServiceForType($account->service_type);

            if ($account->service_type === ServiceType::Openvpn) {
                $this->mikrotikService->createSecret($server, [
                    'name' => $username,
                    'password' => $password,
                    'profile' => $pppProfile,
                ], 'ovpn');
            } elseif ($account->service_type === ServiceType::L2tp) {
                $this->mikrotikService->createSecret($server, [
                    'name' => $username,
                    'password' => $password,
                    'profile' => $pppProfile,
                ], 'l2tp');
            } else {
                $this->mikrotikService->createUser($server, [
                    'name' => $username,
                    'password' => $password,
                    'profile' => $pppProfile,
                    'service' => $service,
                ]);
            }

            if ($account->mikrotik_profile_key === null) {
                $account->update(['mikrotik_profile_key' => $profile->remote_key]);
            }

            if (! $account->remote_password_enc) {
                $account->update(['remote_password_enc' => $password]);
            }

            if (! $shouldEnable) {
                $this->mikrotikService->disableUser($server, $username);
            }

            return [
                'action' => 'created',
                'message' => __('services.mikrotik_user_created', ['username' => $username]),
            ];
        }

        $this->mikrotikService->updateUser($server, $username, array_filter([
            'password' => $account->remote_password_enc,
            'profile' => $pppProfile,
        ], fn ($value) => $value !== null && $value !== ''));

        if ($shouldEnable) {
            $this->mikrotikService->enableUser($server, $username);
        } else {
            $this->mikrotikService->disableUser($server, $username);
        }

        return [
            'action' => 'updated',
            'message' => __('services.mikrotik_user_synced', ['username' => $username]),
        ];
    }

    /**
     * @return array{action: string, message: string}
     */
    protected function pushWireguardAccount(Account $account, bool $onlyMissing): array
    {
        $server = $account->server;
        $publicKey = $account->wireguard_public_key;
        $privateKey = $account->wireguard_private_key_enc;

        if ($publicKey === null || $privateKey === null) {
            throw new RemoteProvisionException(__('services.wireguard_keys_missing'));
        }

        $exists = $this->mikrotikService->wireguardPeerExists($server, $publicKey);
        $shouldEnable = $account->status === AccountStatus::Active
            && ! $account->isExpired()
            && ! $account->isQuotaExhausted();

        if ($exists && $onlyMissing) {
            return [
                'action' => 'skipped',
                'message' => __('services.wireguard_peer_already_existed', ['username' => $account->remote_username]),
            ];
        }

        if (! $exists) {
            $wgInterfaces = app(MikrotikWireguardInterfaceService::class);
            $interface = $this->mikrotikService->wireguardPeerInterface($server, $publicKey)
                ?? $wgInterfaces->resolveInterfaceName($server, null, $account->mikrotik_profile_key);

            $this->mikrotikService->addPeer($server, $interface, [
                'public_key' => $publicKey,
                'allowed_address' => $account->wireguard_address ?? '0.0.0.0/0',
                'comment' => $account->remote_username,
            ]);

            $this->applyWireguardPeerSpeedQueue($server, $interface, (string) ($account->wireguard_address ?? ''));

            if (! $shouldEnable) {
                $this->mikrotikService->disablePeer($server, $publicKey);
            }

            return [
                'action' => 'created',
                'message' => __('services.wireguard_peer_created', ['username' => $account->remote_username, 'interface' => $interface]),
            ];
        }

        // Never silently move an already-provisioned peer to a "less loaded" interface:
        // each WireGuard interface has its own server key pair, so relocating the peer
        // invalidates the client's existing config (wrong server public key) even though
        // the panel account itself looks unchanged. Only move it when the admin explicitly
        // assigned a different profile/interface (mikrotik_profile_key); otherwise keep the
        // peer exactly where it already is on the router.
        $currentInterface = $this->mikrotikService->wireguardPeerInterface($server, $publicKey);
        $wgInterfaces = app(MikrotikWireguardInterfaceService::class);

        $targetInterface = $account->mikrotik_profile_key
            ? $wgInterfaces->resolveInterfaceName($server, null, $account->mikrotik_profile_key, respectProfileKeyWhenFull: true)
            : ($currentInterface ?? $wgInterfaces->resolveInterfaceName($server, null, null));

        $updatePayload = [
            'allowed_address' => $account->wireguard_address ?? '0.0.0.0/0',
            'comment' => $account->remote_username,
        ];

        if ($currentInterface === null || $targetInterface !== $currentInterface) {
            $updatePayload['interface'] = $targetInterface;
        }

        $this->mikrotikService->updatePeer($server, $publicKey, $updatePayload);

        if ($shouldEnable) {
            $this->mikrotikService->enablePeer($server, $publicKey);
        } else {
            $this->mikrotikService->disablePeer($server, $publicKey);
        }

        return [
            'action' => 'updated',
            'message' => __('services.wireguard_peer_synced', ['username' => $account->remote_username]),
        ];
    }

    public function disableRemoteOnly(Account $account): void
    {
        $account->loadMissing('server');
        $server = $account->server;

        if ($account->wireguard_public_key) {
            $this->mikrotikService->removePeer($server, $account->wireguard_public_key);

            return;
        }

        $this->disableRemoteAccount($account);
    }

    protected function generateRemoteUsername(ServiceType $serviceType): string
    {
        if ($this->usesNumericPppCredentials($serviceType)) {
            return $this->generateNumericPppUsername();
        }

        if ($serviceType->isOcserv()) {
            return $this->generateOcservUsername();
        }

        $prefix = $serviceType->usernamePrefix();

        for ($attempt = 0; $attempt < 15; $attempt++) {
            $username = $prefix.Str::lower(Str::random(8));

            if (! Account::query()->where('remote_username', $username)->exists()) {
                return $username;
            }
        }

        throw new \RuntimeException(__('services.unique_username_failed'));
    }

    protected function generateRemotePassword(ServiceType $serviceType): string
    {
        if ($this->usesNumericPppCredentials($serviceType)) {
            return $this->generateUniqueNumericPassword();
        }

        if ($serviceType->isOcserv()) {
            return $this->generateOcservPassword();
        }

        return Str::password(12);
    }

    protected function usesNumericPppCredentials(ServiceType $serviceType): bool
    {
        return $serviceType->isMikrotik()
            && $serviceType->accountCategory() === AccountCategory::Ppp;
    }

    /** OpenConnect: VPL + 6 digits, e.g. VPL847291 */
    protected function generateOcservUsername(): string
    {
        $prefix = (string) config('shahpanel.ocserv.username_prefix', 'VPL');
        $digits = max(4, min(10, (int) config('shahpanel.ocserv.username_digits', 6)));
        $max = (10 ** $digits) - 1;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $username = $prefix.str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);

            if (! Account::query()->where('remote_username', $username)->exists()) {
                return $username;
            }
        }

        throw new \RuntimeException(__('services.unique_username_failed_openconnect'));
    }

    /** OpenConnect: digits + 2 letters, e.g. 847291ab */
    protected function generateOcservPassword(): string
    {
        $digits = max(4, min(10, (int) config('shahpanel.ocserv.password_digits', 6)));
        $letterCount = max(2, min(4, (int) config('shahpanel.ocserv.password_letters', 2)));
        $max = (10 ** $digits) - 1;
        $alphabet = 'abcdefghjkmnpqrstuvwxyz'; // skip i/l/o for readability

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $numeric = str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);
            $letters = '';
            for ($i = 0; $i < $letterCount; $i++) {
                $letters .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $password = $numeric.$letters;

            if (! Account::query()->where('remote_password_enc', $password)->exists()) {
                return $password;
            }
        }

        throw new \RuntimeException(__('services.unique_password_failed_openconnect'));
    }

    /**
     * @param  array<string, mixed>  $clientData
     * @return array{0: string, 1: string}
     */
    protected function resolveMikrotikPppCredentials(ServiceType $serviceType, array $clientData): array
    {
        $forceAuto = ! empty($clientData['auto_random_remote_identity']);
        $manualUsername = trim((string) ($clientData['remote_username'] ?? ''));

        if ($forceAuto || $manualUsername === '') {
            return [
                $this->generateNumericPppUsername(),
                $this->generateUniqueNumericPassword(),
            ];
        }

        return [
            AccountNameValidator::assertValid($manualUsername),
            (string) ($clientData['remote_password'] ?? $this->generateUniqueNumericPassword()),
        ];
    }

    /** user + 6 random digits, e.g. user847291 — unique in panel DB. */
    protected function generateNumericPppUsername(): string
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $username = 'user'.str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

            if (! Account::query()->where('remote_username', $username)->exists()) {
                return $username;
            }
        }

        throw new \RuntimeException(__('services.unique_username_failed'));
    }

    /** 6-digit numeric password — unique in panel DB. */
    protected function generateUniqueNumericPassword(): string
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $password = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

            if (! Account::query()->where('remote_password_enc', $password)->exists()) {
                return $password;
            }
        }

        throw new \RuntimeException(__('services.unique_service_password_failed'));
    }

    /**
     * @param  array<string, mixed>  $clientData
     * @return array{username: string, email: string}
     */
    protected function generateSanaeiClientIdentity(User $seller, Package $package, array $clientData, ?int $clientUserId = null): array
    {
        $clientLabel = $this->resolveSanaeiClientLabel($clientData, $clientUserId);

        if ($clientLabel === '') {
            throw new \InvalidArgumentException(__('accounts.sanaei_client_name_required'));
        }

        $staff = $this->sanaeiStaffUsername($seller);
        $volume = $this->sanaeiVolumeSlugForPurchase($package, $clientData);

        for ($attempt = 0; $attempt < 15; $attempt++) {
            $identity = $staff.'-'.$clientLabel.'-'.$volume.'-'.Str::lower(Str::random(4));

            if (! Account::query()->where('remote_username', $identity)->exists()) {
                return ['username' => $identity, 'email' => $identity];
            }
        }

        throw new \RuntimeException(__('services.unique_username_failed'));
    }

    /**
     * @param  array<string, mixed>  $clientData
     */
    protected function resolveSanaeiClientLabel(array $clientData, ?int $clientUserId = null): string
    {
        if (! empty($clientData['auto_random_remote_identity'])) {
            return Str::lower(Str::random(8));
        }

        $label = $this->normalizeSanaeiClientLabel((string) ($clientData['sanaei_client_name'] ?? ''));

        if ($label !== '') {
            return $label;
        }

        if ($clientUserId !== null) {
            $client = User::query()->find($clientUserId);

            if ($client !== null) {
                $label = $this->normalizeSanaeiClientLabel((string) $client->username);
            }
        }

        return $label;
    }

    protected function normalizeSanaeiClientLabel(string $name): string
    {
        if (trim($name) === '') {
            return '';
        }

        return AccountNameValidator::normalizeSanaeiLabel($name);
    }

    protected function sanaeiStaffUsername(User $seller): string
    {
        $username = Str::lower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $seller->username) ?? '');

        if ($username === '') {
            $username = 'user';
        }

        return Str::substr($username, 0, 32);
    }

    /**
     * @param  array<string, mixed>  $clientData
     */
    protected function sanaeiVolumeSlugForPurchase(Package $package, array $clientData): string
    {
        if ($package->isElastic()) {
            $gb = $this->resolvePurchaseDataGb($package, $clientData);

            if ($gb <= 0) {
                return '0';
            }

            if ((float) (int) $gb === $gb) {
                return (string) (int) $gb;
            }

            return str_replace('.', '-', rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.'));
        }

        return $this->sanaeiVolumeSlug($package);
    }

    protected function sanaeiVolumeSlug(Package $package): string
    {
        if ($package->data_limit_gb === null || (float) $package->data_limit_gb <= 0) {
            return '0';
        }

        $gb = (float) $package->data_limit_gb;

        if ((float) (int) $gb === $gb) {
            return (string) (int) $gb;
        }

        return str_replace('.', '-', rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.'));
    }

    /**
     * @param  array<string, mixed>  $clientData
     */
    /**
     * Resolve the chosen GB for an elastic (accordion) purchase, clamped to the
     * package min/max. Returns 0.0 for non-elastic packages.
     *
     * @param  array<string, mixed>  $clientData
     */
    protected function resolvePurchaseDataGb(Package $package, array $clientData): float
    {
        if (! $package->isElastic()) {
            return 0.0;
        }

        $requested = isset($clientData['data_gb']) && $clientData['data_gb'] !== null && $clientData['data_gb'] !== ''
            ? (float) $clientData['data_gb']
            : (float) ($package->min_data_gb ?? 1);

        return $package->clampDataGb($requested);
    }

    protected function resolvePurchasedDataGb(Account $account): float
    {
        if ($account->purchased_data_gb !== null && (float) $account->purchased_data_gb > 0) {
            return round((float) $account->purchased_data_gb, 2);
        }

        if ($account->data_limit_bytes !== null && (int) $account->data_limit_bytes > 0) {
            return round((int) $account->data_limit_bytes / (1024 ** 3), 2);
        }

        return 0.0;
    }

    /**
     * Apply purchased/limit bytes for elastic renewals (same / add / upgrade).
     */
    protected function applyElasticRenewalVolume(
        Account $account,
        Package $billingPackage,
        string $renewalMode,
        ?float $renewalGb,
    ): void {
        if ($this->accountUsesElasticVolume($account, $billingPackage) && $renewalGb !== null) {
            if ($renewalMode === 'add_volume') {
                $currentPurchased = $this->resolvePurchasedDataGb($account);
                $account->purchased_data_gb = round($currentPurchased + $renewalGb, 2);
                $account->data_limit_bytes = $this->resolveVolumeLimitBytes($account)
                    + (int) round($renewalGb * 1024 * 1024 * 1024);
            } else {
                $account->purchased_data_gb = $renewalGb;
                $account->data_limit_bytes = (int) round($renewalGb * 1024 * 1024 * 1024);
            }

            $this->syncElasticPurchasedFromLimit($account);

            return;
        }

        if (! $billingPackage->isUnlimited() && ! $this->accountUsesElasticVolume($account, $billingPackage)) {
            $account->data_limit_bytes = (int) round((float) $billingPackage->data_limit_gb * 1024 * 1024 * 1024);
        }
    }

    protected function accountUsesElasticVolume(Account $account, Package $billingPackage): bool
    {
        if ($billingPackage->isElastic()) {
            return true;
        }

        $account->loadMissing('package');

        if ($account->package?->isElastic()) {
            return true;
        }

        return $account->purchased_data_gb !== null && (float) $account->purchased_data_gb > 0;
    }

    /**
     * Add purchased/limit volume without resetting usage (repair or manual top-up).
     */
    public function applyVolumeTopUp(Account $account, float $addGb, bool $syncRemote = true): Account
    {
        $addGb = round(max(0.01, $addGb), 2);
        $account->loadMissing(['server', 'package', 'packageDuration']);

        if ($syncRemote && $account->server !== null) {
            $this->syncUsedBytesFromRemote($account);
            $account->refresh();
        }

        $currentPurchased = $this->resolvePurchasedDataGb($account);
        $account->purchased_data_gb = round($currentPurchased + $addGb, 2);
        $account->data_limit_bytes = $this->resolveVolumeLimitBytes($account)
            + (int) round($addGb * 1024 * 1024 * 1024);
        $this->syncElasticPurchasedFromLimit($account);

        if (! $account->isQuotaExhausted()) {
            $account->status = AccountStatus::Active;
        }

        $account->save();

        if ($syncRemote && $account->server !== null) {
            $this->renewRemoteAccount($account->fresh(), resetTraffic: false, forceEnable: true);
            $this->reconcilePanelQuotaAfterRenewal($account->fresh(), resetTraffic: false);
        }

        return $account->fresh();
    }

    /**
     * Set purchased/limit to an exact GB total (repair / manual correction).
     */
    public function setPurchasedVolumeGb(Account $account, float $targetGb, bool $syncRemote = true): Account
    {
        $targetGb = round(max(0.01, $targetGb), 2);
        $account->loadMissing(['server', 'package', 'packageDuration']);

        if ($syncRemote && $account->server !== null) {
            $this->syncUsedBytesFromRemote($account);
            $account->refresh();
        }

        $limitBytes = (int) round($targetGb * 1024 * 1024 * 1024);
        $usedBytes = (int) $account->data_used_bytes;

        if ($usedBytes > $limitBytes) {
            throw new \InvalidArgumentException(__('services.account_limit_below_usage', [
                'limit' => rtrim(rtrim(number_format($targetGb, 2, '.', ''), '0'), '.'),
                'used' => format_data_size($usedBytes),
            ]));
        }

        $account->purchased_data_gb = $targetGb;
        $account->data_limit_bytes = $limitBytes;

        if (! $account->isQuotaExhausted()) {
            $account->status = AccountStatus::Active;
        }

        $account->save();

        if ($syncRemote && $account->server !== null) {
            $this->renewRemoteAccount($account->fresh(), resetTraffic: false, forceEnable: true);
            $this->reconcilePanelQuotaAfterRenewal($account->fresh(), resetTraffic: false);
        }

        return $account->fresh();
    }

    protected function resolveVolumeLimitBytes(Account $account): int
    {
        $limit = (int) ($account->data_limit_bytes ?? 0);

        if ($limit > 0) {
            return $limit;
        }

        $purchasedGb = $this->resolvePurchasedDataGb($account);

        if ($purchasedGb <= 0) {
            return 0;
        }

        return (int) round($purchasedGb * 1024 * 1024 * 1024);
    }

    /**
     * Keep purchased_data_gb aligned with authoritative data_limit_bytes for elastic accounts.
     */
    protected function syncElasticPurchasedFromLimit(Account $account): void
    {
        $limitBytes = (int) ($account->data_limit_bytes ?? 0);

        if ($limitBytes <= 0) {
            return;
        }

        $account->purchased_data_gb = round($limitBytes / (1024 ** 3), 2);
    }

    public function readsUsageFromRemotePanel(Account $account): bool
    {
        return $this->usesPanelTrafficAccounting($account);
    }

    protected function usesPanelTrafficAccounting(Account $account): bool
    {
        $account->loadMissing('server');
        $server = $account->server;

        if ($server === null) {
            return false;
        }

        return $server->isPasarguard()
            || $account->service_type->isPasarguard()
            || (bool) $account->pasarguard_user_id
            || $server->isRemnawave()
            || $account->service_type->isRemnawave()
            || (bool) $account->remnawave_uuid;
    }

    protected function resolvePanelShouldEnable(Account $account, bool $forceEnable = false): bool
    {
        if ($account->status !== AccountStatus::Active || $account->isExpired()) {
            return false;
        }

        if ($forceEnable) {
            return true;
        }

        return ! $account->isQuotaExhausted();
    }

    protected function resetPasarguardTrafficOrFail(Account $account): void
    {
        try {
            $this->pasarguardService->resetUserTraffic($account->server, $account->remote_username);
        } catch (Throwable $exception) {
            Log::channel('pasarguard')->error('PasarGuard traffic reset on renewal failed', [
                'account_id' => $account->id,
                'username' => $account->remote_username,
                'error' => $exception->getMessage(),
            ]);

            throw new RemoteProvisionException(
                __('services.pasarguard_traffic_reset_failed', ['error' => $exception->getMessage()]),
                previous: $exception,
            );
        }
    }

    protected function resetRemnawaveTrafficOrFail(Account $account, string $uuid): void
    {
        try {
            $this->remnawaveService->resetUserTraffic(
                $account->server,
                $uuid,
                (string) $account->remote_username,
            );
        } catch (Throwable $exception) {
            Log::channel('remnawave')->error('Remnawave traffic reset on renewal failed', [
                'account_id' => $account->id,
                'uuid' => $uuid,
                'error' => $exception->getMessage(),
            ]);

            throw new RemoteProvisionException(
                __('services.remnawave_traffic_reset_failed', ['error' => $exception->getMessage()]),
                previous: $exception,
            );
        }
    }

    protected function reconcilePanelQuotaAfterRenewal(Account $account, bool $resetTraffic): void
    {
        if (! $this->usesPanelTrafficAccounting($account)) {
            return;
        }

        $this->syncUsedBytesFromRemote($account);
        $account->refresh();

        if ($resetTraffic) {
            $account->update(['data_used_bytes' => 0]);
        }

        $updates = [];

        if ($account->status !== AccountStatus::Active && ! $account->isQuotaExhausted() && ! $account->isExpired()) {
            $updates['status'] = AccountStatus::Active;
        }

        if ($updates !== []) {
            $account->update($updates);
            $account->refresh();
        }

        if ($account->status === AccountStatus::Active && ! $account->isExpired() && $account->isQuotaExhausted()) {
            if ($resetTraffic) {
                throw new RemoteProvisionException(__('services.account_renew_traffic_not_reset'));
            }

            $this->pushAccountToServer($account->fresh(), onlyMissing: false);
            $this->syncUsedBytesFromRemote($account);
            $account->refresh();

            if ($account->isQuotaExhausted()) {
                throw new RemoteProvisionException(__('services.account_volume_increase_not_applied'));
            }

            if ($account->status !== AccountStatus::Active) {
                $account->update(['status' => AccountStatus::Active]);
            }
        }

        $this->ensurePanelQuotaMatchesDatabase($account->fresh(), $resetTraffic);
        $this->rebasePanelTrafficSyncSnapshot($account->fresh());
    }

    /**
     * Push DB quota to Pasarguard/Remnawave when the remote limit drifts (renewal / enable / repair).
     *
     * @throws RemoteProvisionException
     */
    public function ensurePanelQuotaMatchesDatabase(Account $account, bool $resetTrafficIfNeeded = false): void
    {
        if (! $this->usesPanelTrafficAccounting($account) || $account->isUnlimited()) {
            return;
        }

        $account->loadMissing(['server', 'package', 'packageDuration']);
        $localLimit = (int) ($account->data_limit_bytes ?? 0);

        if ($localLimit <= 0) {
            return;
        }

        $remoteLimit = $this->readRemoteLimitBytes($account);

        if ($remoteLimit === null) {
            return;
        }

        $tolerance = 1024 * 1024;

        if (abs($localLimit - $remoteLimit) <= $tolerance) {
            $this->syncUsedBytesFromRemote($account);

            return;
        }

        Log::channel('pasarguard')->warning('Panel data_limit drift — pushing DB quota to remote panel', [
            'account_id' => $account->id,
            'username' => $account->remote_username,
            'local_limit' => $localLimit,
            'remote_limit' => $remoteLimit,
        ]);

        $this->pushAccountToServer($account, onlyMissing: false);

        $remoteAfterPush = $this->readRemoteLimitBytes($account->fresh());

        if ($remoteAfterPush !== null && abs($localLimit - $remoteAfterPush) <= $tolerance) {
            $this->syncUsedBytesFromRemote($account->fresh());

            return;
        }

        if ($resetTrafficIfNeeded) {
            $this->resetPanelTrafficForAccount($account);
            $this->pushAccountToServer($account->fresh(), onlyMissing: false);

            $remoteAfterReset = $this->readRemoteLimitBytes($account->fresh());

            if ($remoteAfterReset !== null && abs($localLimit - $remoteAfterReset) <= $tolerance) {
                $account->update(['data_used_bytes' => 0]);
                $this->syncUsedBytesFromRemote($account->fresh());

                return;
            }
        }

        throw new RemoteProvisionException(__('services.account_panel_limit_mismatch', [
            'remote' => format_data_size($remoteAfterPush ?? $remoteLimit),
            'local' => format_data_size($localLimit),
        ]));
    }

    protected function readRemoteLimitBytes(Account $account): ?int
    {
        $account->loadMissing('server');
        $server = $account->server;

        if ($server === null) {
            return null;
        }

        try {
            if ($server->isPasarguard() || $account->service_type->isPasarguard() || $account->pasarguard_user_id) {
                $remote = $this->pasarguardService->getUser($server, $account->remote_username);

                return $this->pasarguardService->normalizeTrafficSnapshot($remote)['limit_bytes'];
            }

            if ($server->isRemnawave() || $account->service_type->isRemnawave() || $account->remnawave_uuid) {
                $remote = $this->remnawaveService->getUser($server, $account->remote_username);

                if ($remote === null) {
                    return null;
                }

                return $this->remnawaveService->normalizeTrafficSnapshot($remote)['limit_bytes'];
            }
        } catch (Throwable $exception) {
            Log::channel('pasarguard')->warning('Failed to read remote panel quota', [
                'account_id' => $account->id,
                'username' => $account->remote_username,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }

    protected function resetPanelTrafficForAccount(Account $account): void
    {
        $account->loadMissing('server');
        $server = $account->server;

        if ($server === null) {
            return;
        }

        if ($server->isPasarguard() || $account->service_type->isPasarguard() || $account->pasarguard_user_id) {
            $this->resetPasarguardTrafficOrFail($account);

            return;
        }

        if ($server->isOcserv() || $account->service_type->isOcserv()) {
            // ocserv has no per-user traffic counters in the panel API — nothing to reset.
            return;
        }

        if ($server->isCiscoAnyconnect() || $account->service_type->isCiscoAnyconnect() || $account->cisco_asa_username) {
            // ASA local users have no per-user traffic counters via REST — nothing to reset.
            return;
        }

        if ($server->isRemnawave() || $account->service_type->isRemnawave() || $account->remnawave_uuid) {
            $uuid = $this->resolveRemnawaveUuid($account);

            if ($uuid !== null) {
                $this->resetRemnawaveTrafficOrFail($account, $uuid);
            }
        }
    }

    protected function rebasePanelTrafficSyncSnapshot(Account $account): void
    {
        if (! $this->usesPanelTrafficAccounting($account)) {
            return;
        }

        $account->loadMissing('server');
        $server = $account->server;

        if ($server === null) {
            return;
        }

        try {
            if ($server->isPasarguard() || $account->service_type->isPasarguard() || $account->pasarguard_user_id) {
                $remote = $this->pasarguardService->getUser($server, $account->remote_username);
                $normalized = $this->pasarguardService->normalizeTrafficSnapshot($remote);
                $used = max(0, (int) ($normalized['used_bytes'] ?? 0));
            } elseif ($server->isRemnawave() || $account->service_type->isRemnawave() || $account->remnawave_uuid) {
                $remote = $this->remnawaveService->getUser($server, $account->remote_username);
                if ($remote === null) {
                    return;
                }
                $normalized = $this->remnawaveService->normalizeTrafficSnapshot($remote);
                $used = max(0, (int) ($normalized['used_bytes'] ?? 0));
            } else {
                return;
            }

            AccountUsageLog::query()->create([
                'account_id' => $account->id,
                'rx_delta_bytes' => 0,
                'tx_delta_bytes' => 0,
                'rx_snapshot' => $used,
                'tx_snapshot' => 0,
                'recorded_at' => now(),
            ]);

            $account->update(['data_used_bytes' => $used]);
        } catch (Throwable) {
            // baseline sync is best-effort
        }
    }

    /**
     * Read panel used traffic into shahpanel (does not change limits or reset panel traffic).
     */
    public function syncUsedBytesFromRemotePanel(Account $account): Account
    {
        $this->syncUsedBytesFromRemote($account);

        return $account->fresh();
    }

    /**
     * Sync live usage from panel, reconcile with usage logs for the current billing period, and persist.
     */
    public function refreshUsageFromPanelAndLogs(Account $account): Account
    {
        $account->loadMissing('server');

        if ($account->server !== null) {
            try {
                app(SyncService::class)->syncAccount($account);
                $account->refresh();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $resolvedUsed = $this->resolveAuthoritativeUsedBytes($account);

        if ($resolvedUsed !== (int) $account->data_used_bytes) {
            $account->update(['data_used_bytes' => $resolvedUsed]);
            $account->refresh();
        }

        return $account;
    }

    /**
     * Consumed bytes for the current billing period (since last purchase/renewal invoice).
     */
    public function resolveAuthoritativeUsedBytesForDisplay(Account $account): int
    {
        return $this->resolveAuthoritativeUsedBytes($account);
    }

    protected function resolveAuthoritativeUsedBytes(Account $account): int
    {
        $fromDb = max(0, (int) $account->data_used_bytes);

        // Pasarguard / Remnawave: DB already mirrors the panel after syncAccount();
        // the panel is authoritative for consumed traffic, so never inflate it with
        // the lifetime sum of logged deltas (those span resets/renewals).
        if ($this->readsUsageFromRemotePanel($account)) {
            return $fromDb;
        }

        $periodStart = $this->lastBillingEventAt($account);
        $loggedSum = $this->sumLoggedUsageSince($account, $periodStart);

        if ($this->usesAbsoluteTrafficSync($account)) {
            $latestLog = AccountUsageLog::query()
                ->where('account_id', $account->id)
                ->where('recorded_at', '>=', $periodStart)
                ->orderByDesc('recorded_at')
                ->first();

            $fromSnapshot = $latestLog !== null
                ? max(0, (int) $latestLog->rx_snapshot + (int) $latestLog->tx_snapshot)
                : 0;

            return max($fromDb, $fromSnapshot, $loggedSum);
        }

        return max($fromDb, $loggedSum);
    }

    protected function lastBillingEventAt(Account $account): \Illuminate\Support\Carbon
    {
        $issuedAt = Invoice::query()
            ->where('account_id', $account->id)
            ->whereIn('type', [InvoiceType::NewAccount, InvoiceType::Renewal])
            ->orderByDesc('issued_at')
            ->value('issued_at');

        return $issuedAt ?? $account->created_at ?? now();
    }

    protected function sumLoggedUsageSince(Account $account, \Illuminate\Support\Carbon $since): int
    {
        return (int) AccountUsageLog::query()
            ->where('account_id', $account->id)
            ->where('recorded_at', '>=', $since)
            ->get()
            ->sum(fn (AccountUsageLog $log): int => max(0, (int) $log->rx_delta_bytes) + max(0, (int) $log->tx_delta_bytes));
    }

    protected function usesAbsoluteTrafficSync(Account $account): bool
    {
        $account->loadMissing('server');
        $server = $account->server;

        if ($server === null) {
            return false;
        }

        return $this->usesPanelTrafficAccounting($account)
            || $server->isSanaei()
            || $account->service_type->isSanaei();
    }

    /**
     * Enable Pasarguard/Remnawave user using current DB limits/expiry — no volume reset or limit change.
     */
    public function enablePanelAccountWithoutQuotaChanges(Account $account): void
    {
        $account->loadMissing(['server', 'package', 'packageDuration']);

        if (! $this->usesPanelTrafficAccounting($account)) {
            throw new \InvalidArgumentException(__('services.only_pasarguard_remnawave_supported'));
        }

        $this->renewRemoteAccount($account, resetTraffic: false, forceEnable: true);
    }

    protected function syncUsedBytesFromRemote(Account $account): void
    {
        $account->loadMissing('server');
        $server = $account->server;

        if ($server === null) {
            return;
        }

        try {
            if ($server->isPasarguard() || $account->service_type->isPasarguard() || $account->pasarguard_user_id) {
                $remote = $this->pasarguardService->getUser($server, $account->remote_username);
                $used = (int) ($remote['used_traffic'] ?? 0);
                $account->update(['data_used_bytes' => max(0, $used)]);

                return;
            }

            if ($server->isRemnawave() || $account->service_type->isRemnawave() || $account->remnawave_uuid) {
                $remote = $this->remnawaveService->getUser($server, $account->remote_username);
                if ($remote !== null) {
                    $snap = $this->remnawaveService->normalizeTrafficSnapshot($remote);
                    $account->update(['data_used_bytes' => max(0, (int) $snap['used_bytes'])]);
                }
            }
        } catch (Throwable) {
            // keep local usage if panel unreachable
        }
    }

    protected function provisionRemoteAccount(
        User $seller,
        int $ownerAgentId,
        Package $package,
        Server $server,
        PackageDuration $duration,
        array $clientData,
        ?int $clientUserId = null
    ): Account {
        if ($package->service_type->isPanelV2ray() && empty($clientData['remote_username'])) {
            $sanaeiIdentity = $this->generateSanaeiClientIdentity($seller, $package, $clientData, $clientUserId);
            $username = $sanaeiIdentity['username'];
            $email = ! empty($clientData['client_email'])
                ? (string) $clientData['client_email']
                : $sanaeiIdentity['email'];
            $password = (string) ($clientData['remote_password'] ?? $this->generateRemotePassword($package->service_type));
        } elseif ($this->usesNumericPppCredentials($package->service_type)) {
            [$username, $password] = $this->resolveMikrotikPppCredentials($package->service_type, $clientData);
            $email = ! empty($clientData['client_email'])
                ? (string) $clientData['client_email']
                : $username.'@shahpanel.local';
        } else {
            $username = ! empty($clientData['remote_username'])
                ? AccountNameValidator::assertValid((string) $clientData['remote_username'])
                : $this->generateRemoteUsername($package->service_type);
            $email = ! empty($clientData['client_email'])
                ? (string) $clientData['client_email']
                : ($package->service_type->isPanelV2ray()
                    ? $username
                    : $username.'@shahpanel.local');
            $password = (string) ($clientData['remote_password'] ?? $this->generateRemotePassword($package->service_type));
        }

        $portalPassword = (string) ($clientData['client_password'] ?? $clientData['client_panel_password'] ?? Str::password(10));

        if ($package->isElastic()) {
            $purchasedGb = $this->resolvePurchaseDataGb($package, $clientData);
            $dataLimitBytes = (int) round($purchasedGb * 1024 * 1024 * 1024);
        } else {
            $purchasedGb = null;
            $dataLimitBytes = $package->isUnlimited()
                ? null
                : (int) round((float) $package->data_limit_gb * 1024 * 1024 * 1024);
        }

        $expiryAt = array_key_exists('custom_expiry_at', $clientData)
            ? ($clientData['custom_expiry_at'] === null
                ? null
                : ($clientData['custom_expiry_at'] instanceof \Carbon\Carbon
                    ? $clientData['custom_expiry_at']
                    : \Illuminate\Support\Carbon::parse($clientData['custom_expiry_at'])))
            : $duration->expiryFromNow();

        if ($server->isMikrotik() && $package->mikrotikProfileKeys() !== []) {
            $clientData['mikrotik_profile_keys'] = $package->mikrotikProfileKeys();
            if ($package->service_type === ServiceType::Wireguard && count($package->mikrotikProfileKeys()) === 1) {
                $clientData['wireguard_profile_key'] = $package->mikrotikProfileKeys()[0];
            }
        }

        $remoteMeta = $this->createRemoteService($server, $package, $duration, $username, $password, $email, $dataLimitBytes, $expiryAt, $clientData);

        $account = Account::query()->create([
            'owner_seller_id' => $seller->id,
            'owner_agent_id' => $ownerAgentId,
            'client_user_id' => $clientUserId,
            'display_label' => filled($clientData['display_label'] ?? null)
                ? (string) $clientData['display_label']
                : null,
            'package_id' => $package->id,
            'package_duration_id' => $duration->id,
            'server_id' => $server->id,
            'mikrotik_profile_key' => $remoteMeta['mikrotik_profile_key'] ?? null,
            'service_type' => $package->service_type,
            'remote_username' => $username,
            'remote_password_enc' => $password,
            'wireguard_private_key_enc' => $remoteMeta['wireguard_private_key'] ?? null,
            'wireguard_public_key' => $remoteMeta['wireguard_public_key'] ?? null,
            'wireguard_address' => $remoteMeta['wireguard_address'] ?? null,
            'sanaei_inbound_id' => $remoteMeta['sanaei_inbound_id'] ?? null,
            'sanaei_client_uuid' => $remoteMeta['sanaei_client_uuid'] ?? null,
            'sanaei_sub_id' => $remoteMeta['sanaei_sub_id'] ?? null,
            'pasarguard_user_id' => $remoteMeta['pasarguard_user_id'] ?? null,
            'pasarguard_subscription_url' => $remoteMeta['pasarguard_subscription_url'] ?? null,
            'remnawave_uuid' => $remoteMeta['remnawave_uuid'] ?? null,
            'remnawave_subscription_url' => $remoteMeta['remnawave_subscription_url'] ?? null,
            'cisco_asa_username' => $remoteMeta['cisco_asa_username'] ?? null,
            'client_email' => $email,
            'client_panel_password_hash' => $portalPassword,
            'portal_token' => Str::random((int) config('shahpanel.portal_token_length', 32)),
            'data_limit_bytes' => $dataLimitBytes,
            'purchased_data_gb' => $purchasedGb,
            'data_used_bytes' => 0,
            'expiry_at' => $expiryAt,
            'status' => AccountStatus::Active,
        ]);

        if ($package->service_type === ServiceType::Wireguard && $account->wireguard_public_key) {
            try {
                $this->pushWireguardAccount($account, true);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $account;
    }

    /**
     * @param  array<string, mixed>  $clientData
     * @return array<string, mixed>
     */
    protected function createRemoteService(
        Server $server,
        Package $package,
        PackageDuration $duration,
        string $username,
        string $password,
        string $email,
        ?int $dataLimitBytes,
        ?\DateTimeInterface $expiryAt,
        array $clientData
    ): array {
        $serviceType = $package->service_type;
        $totalGB = $dataLimitBytes ? $dataLimitBytes / (1024 ** 3) : null;
        $expiryMs = $expiryAt !== null ? (int) ($expiryAt->getTimestamp() * 1000) : 0;

        if ($server->isPasarguard() || $serviceType->isPasarguard()) {
            $remote = $this->pasarguardService->createPanelUser(
                $server,
                $package,
                $duration,
                $username,
                $dataLimitBytes,
                $expiryAt instanceof \Illuminate\Support\Carbon ? $expiryAt : ($expiryAt ? \Illuminate\Support\Carbon::instance($expiryAt) : null),
            );

            return [
                'pasarguard_user_id' => (int) ($remote['id'] ?? 0) ?: null,
                'pasarguard_subscription_url' => (string) ($remote['subscription_url'] ?? ''),
            ];
        }

        if ($server->isOcserv() || $serviceType->isOcserv()) {
            $this->ocservService->createVpnUser(
                $server,
                $package,
                $username,
                $password,
            );

            return [
                'ocserv_username' => $username,
            ];
        }

        if ($server->isCiscoAnyconnect() || $serviceType->isCiscoAnyconnect()) {
            $this->ciscoAnyconnectService->createVpnUser(
                $server,
                $package,
                $username,
                $password,
            );

            return [
                'cisco_asa_username' => $username,
            ];
        }

        if ($server->isRemnawave() || $serviceType->isRemnawave()) {
            $remote = $this->remnawaveService->createPanelUser(
                $server,
                $package,
                $duration,
                $username,
                $dataLimitBytes,
                $expiryAt instanceof \Illuminate\Support\Carbon ? $expiryAt : ($expiryAt ? \Illuminate\Support\Carbon::instance($expiryAt) : null),
            );

            return [
                'remnawave_uuid' => \App\Services\Remnawave\RemnawaveUserIdentity::fromRemoteUser($remote),
                'remnawave_subscription_url' => (string) ($remote['subscriptionUrl'] ?? ''),
            ];
        }

        if ($serviceType->isSanaei() || $server->isSanaei()) {
            $uuid = (string) Str::uuid();
            // چرا: اکانت دقیقاً روی همان inboundهایی ساخته می‌شود که ادمین روی
            // پکیج انتخاب کرده است؛ خالی یعنی همه inboundهای فعال.
            $inboundIds = $this->sanaeiService->resolveProvisionInboundIds(
                $server,
                $package->sanaeiInboundIds()
            );
            $client = $this->sanaeiService->createClient(
                $server,
                $email,
                $uuid,
                limitIp: (int) ($clientData['limit_ip'] ?? 0),
                totalGB: $totalGB,
                expiryTime: $expiryMs,
                inboundIds: $inboundIds,
            );

            return [
                // inbound اصلی = جایی که ایمیل پایه روی آن ساخته شد.
                'sanaei_inbound_id' => $inboundIds[0],
                'sanaei_client_uuid' => $uuid,
                'sanaei_sub_id' => $client['subId'] ?? null,
            ];
        }

        return match ($serviceType) {
            ServiceType::Wireguard => $this->provisionWireguard($server, $username, $clientData),
            ServiceType::Openvpn => $this->provisionOpenVpn(
                $server,
                $username,
                $password,
                $clientData['mikrotik_profile_key'] ?? null,
                $clientData['mikrotik_profile_keys'] ?? null,
            ),
            ServiceType::L2tp => $this->provisionL2tp(
                $server,
                $username,
                $password,
                $clientData['mikrotik_profile_key'] ?? null,
                $clientData['mikrotik_profile_keys'] ?? null,
            ),
            default => $this->provisionPpp(
                $server,
                $username,
                $password,
                $clientData['mikrotik_profile_key'] ?? null,
                $clientData['mikrotik_profile_keys'] ?? null,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $clientData
     * @return array<string, mixed>
     */
    protected function provisionWireguard(Server $server, string $username, array $clientData): array
    {
        $keys = $this->mikrotikService->generateKeys();
        $wgInterfaces = app(MikrotikWireguardInterfaceService::class);

        $allowedKeys = isset($clientData['mikrotik_profile_keys']) && is_array($clientData['mikrotik_profile_keys'])
            ? array_values(array_filter(array_map('strval', $clientData['mikrotik_profile_keys'])))
            : [];

        if ($allowedKeys !== []) {
            $interface = $wgInterfaces->resolveInterfaceNameFromAllowedKeys($server, $allowedKeys);
        } else {
            $interface = $wgInterfaces->resolveInterfaceName(
                $server,
                isset($clientData['wireguard_interface']) ? (string) $clientData['wireguard_interface'] : null,
                isset($clientData['wireguard_profile_key']) ? (string) $clientData['wireguard_profile_key'] : null,
            );
        }
        $profileKey = $wgInterfaces->profileKeyForInterface($interface);

        $address = (string) ($clientData['wireguard_address'] ?? '');
        if ($address === '') {
            $subnet = $clientData['wireguard_subnet'] ?? null;
            if (! is_string($subnet) || $subnet === '') {
                $subnet = $wgInterfaces->resolveSubnet($server, $interface)
                    ?? (string) config('shahpanel.wireguard.default_subnet', '10.10.0.0/24');
            }

            try {
                $subnet = $wgInterfaces->normalizeSubnetCidr($subnet);
            } catch (\InvalidArgumentException) {
                throw new RemoteProvisionException(__('servers.wireguard_subnet_invalid'));
            }

            $address = $this->allocateWireguardAddress($server, $subnet);
        }

        $this->mikrotikService->addPeer($server, $interface, [
            'public_key' => $keys['public_key'],
            'allowed_address' => $address,
            'comment' => $username,
        ]);

        $this->applyWireguardPeerSpeedQueue($server, $interface, $address);

        if (! $this->mikrotikService->wireguardPeerExists($server, $keys['public_key'])) {
            throw new RemoteProvisionException(
                __('services.wireguard_peer_create_failed', ['username' => $username, 'interface' => $interface])
            );
        }

        return [
            'wireguard_private_key' => $keys['private_key'],
            'wireguard_public_key' => $keys['public_key'],
            'wireguard_address' => $address,
            'mikrotik_profile_key' => $profileKey,
        ];
    }

    /**
     * Re-resolve the WireGuard interface + address for a *different* target server based on
     * the account's package — never reuse the old server's interface/IP. Address pools and
     * existing peers are per-server even when two MikroTik routers share an identical interface
     * layout, so blindly copying the old IP risks colliding with an unrelated peer on the new
     * server. Called by AccountTransferService right after server_id changes, before the peer
     * is (re)created on the new server.
     */
    public function reprovisionWireguardForServer(Account $account, Server $newServer): void
    {
        if ($account->service_type !== ServiceType::Wireguard || ! $newServer->isMikrotik()) {
            return;
        }

        $account->loadMissing('package');
        $allowedKeys = $account->package?->mikrotikProfileKeys() ?? [];

        $wgInterfaces = app(MikrotikWireguardInterfaceService::class);
        $interface = $allowedKeys !== []
            ? $wgInterfaces->randomAllowedInterfaceName($newServer, $allowedKeys)
            : $wgInterfaces->resolveInterfaceName($newServer, null, null);

        $subnet = $wgInterfaces->resolveSubnet($newServer, $interface)
            ?? (string) config('shahpanel.wireguard.default_subnet', '10.10.0.0/24');

        try {
            $subnet = $wgInterfaces->normalizeSubnetCidr($subnet);
        } catch (\InvalidArgumentException) {
            throw new RemoteProvisionException(__('servers.wireguard_subnet_invalid'));
        }

        $address = $this->allocateWireguardAddress($newServer, $subnet);

        $account->update([
            'wireguard_address' => $address,
            'mikrotik_profile_key' => $wgInterfaces->profileKeyForInterface($interface),
        ]);
    }

    /**
     * Allocate a random free /32 inside a WireGuard subnet (gateway is .1).
     */
    protected function allocateWireguardAddress(Server $server, string $subnetCidr): string
    {
        [$base, $prefixRaw] = array_pad(explode('/', trim($subnetCidr), 2), 2, '24');
        $prefix = (int) $prefixRaw;

        if ($prefix < 1 || $prefix > 32) {
            throw new RemoteProvisionException(__('servers.wireguard_subnet_invalid'));
        }

        $baseLong = ip2long($base);
        if ($baseLong === false) {
            throw new RemoteProvisionException(__('servers.wireguard_subnet_invalid'));
        }

        $size = 1 << (32 - $prefix);
        $network = $baseLong & (~($size - 1) & 0xFFFFFFFF);
        $first = $network + 2; // .1 is the gateway on the WG interface
        $last = $network + $size - 2;

        $used = Account::query()
            ->where('server_id', $server->id)
            ->whereNotNull('wireguard_address')
            ->pluck('wireguard_address')
            ->map(fn ($address) => ip2long((string) strtok((string) $address, '/')))
            ->filter(fn ($long) => $long !== false && $long >= $first && $long <= $last)
            ->flip()
            ->all();

        $free = [];
        for ($ip = $first; $ip <= $last; $ip++) {
            if (! isset($used[$ip])) {
                $free[] = $ip;
            }
        }

        if ($free === []) {
            throw new RemoteProvisionException(__('services.wireguard_no_free_address'));
        }

        $ip = $free[random_int(0, count($free) - 1)];

        return long2ip($ip).'/32';
    }

    protected function applyWireguardPeerSpeedQueue(Server $server, string $interfaceName, string $addressCidr): void
    {
        if ($addressCidr === '') {
            return;
        }

        $key = app(MikrotikProfileService::class)->wireguardRemoteKey($interfaceName);
        $iface = ServerInterface::query()
            ->where('server_id', $server->id)
            ->where('remote_key', $key)
            ->first();

        if ($iface === null) {
            return;
        }

        $speed = app(MikrotikQueueService::class)->speedLimitMbpsFromMeta($iface->meta ?? []);

        if ($speed === null) {
            return;
        }

        try {
            app(MikrotikQueueService::class)->ensurePeerQueue($server, $interfaceName, $addressCidr, $speed);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function provisionOpenVpn(
        Server $server,
        string $username,
        string $password,
        ?string $profileKey = null,
        ?array $allowedProfileKeys = null,
    ): array {
        $pppProfiles = app(MikrotikPppProfileService::class);
        $profile = is_array($allowedProfileKeys) && $allowedProfileKeys !== []
            ? $pppProfiles->resolveProfileFromAllowedKeys($server, ServiceType::Openvpn, $allowedProfileKeys)
            : $this->mikrotikProfileService->resolve($server, ServiceType::Openvpn, $profileKey);
        $pppProfile = $this->mikrotikProfileService->pppProfileName($profile);

        $this->mikrotikService->createSecret($server, [
            'name' => $username,
            'password' => $password,
            'profile' => $pppProfile,
        ], 'ovpn');

        return ['mikrotik_profile_key' => $profile->remote_key];
    }

    protected function provisionL2tp(
        Server $server,
        string $username,
        string $password,
        ?string $profileKey = null,
        ?array $allowedProfileKeys = null,
    ): array {
        $pppProfiles = app(MikrotikPppProfileService::class);
        $profile = is_array($allowedProfileKeys) && $allowedProfileKeys !== []
            ? $pppProfiles->resolveProfileFromAllowedKeys($server, ServiceType::L2tp, $allowedProfileKeys)
            : $this->mikrotikProfileService->resolve($server, ServiceType::L2tp, $profileKey);
        $pppProfile = $this->mikrotikProfileService->pppProfileName($profile);

        $this->mikrotikService->createSecret($server, [
            'name' => $username,
            'password' => $password,
            'profile' => $pppProfile,
        ], 'l2tp');

        return ['mikrotik_profile_key' => $profile->remote_key];
    }

    protected function provisionPpp(
        Server $server,
        string $username,
        string $password,
        ?string $profileKey = null,
        ?array $allowedProfileKeys = null,
    ): array {
        $pppProfiles = app(MikrotikPppProfileService::class);
        $profile = is_array($allowedProfileKeys) && $allowedProfileKeys !== []
            ? $pppProfiles->resolveProfileFromAllowedKeys($server, ServiceType::Ppp, $allowedProfileKeys)
            : $this->mikrotikProfileService->resolve($server, ServiceType::Ppp, $profileKey);
        $pppProfile = $this->mikrotikProfileService->pppProfileName($profile);

        $this->mikrotikService->createUser($server, [
            'name' => $username,
            'password' => $password,
            'profile' => $pppProfile,
            'service' => 'any',
        ]);

        return ['mikrotik_profile_key' => $profile->remote_key];
    }

    protected function renewRemoteAccount(Account $account, bool $resetTraffic = true, bool $forceEnable = false): void
    {
        $server = $account->server;
        $package = $account->package;
        $totalGB = $account->isUnlimited() ? null : $account->data_limit_bytes / (1024 ** 3);
        $expiryMs = $account->expiry_at ? (int) ($account->expiry_at->getTimestamp() * 1000) : 0;

        if ($server->isPasarguard() || $account->service_type->isPasarguard() || $account->pasarguard_user_id) {
            $this->renewPasarguardAccount($account, $resetTraffic, $forceEnable);

            return;
        }

        if ($server->isOcserv() || $account->service_type->isOcserv()) {
            $this->renewOcservAccount($account, $resetTraffic, $forceEnable);

            return;
        }

        if ($server->isCiscoAnyconnect() || $account->service_type->isCiscoAnyconnect() || $account->cisco_asa_username) {
            $this->renewCiscoAnyconnectAccount($account, $resetTraffic, $forceEnable);

            return;
        }

        if ($server->isRemnawave() || $account->service_type->isRemnawave() || $account->remnawave_uuid) {
            $this->renewRemnawaveAccount($account, $resetTraffic, $forceEnable);

            return;
        }

        if ($account->service_type->isSanaei() && $account->sanaei_client_uuid) {
            $email = $account->client_email ?? $account->remote_username.'@shahpanel.local';
            $this->sanaeiService->updateAccountClients($server, $email, $account->sanaei_client_uuid, [
                'totalGB' => $totalGB,
                'expiryTime' => $expiryMs,
                'enable' => true,
            ], $account->sanaei_inbound_id ?: null);

            return;
        }

        if ($account->service_type === ServiceType::Wireguard && $account->wireguard_public_key) {
            if ($this->mikrotikService->wireguardPeerExists($server, $account->wireguard_public_key)) {
                $this->mikrotikService->enablePeer($server, $account->wireguard_public_key);
            } else {
                $this->pushWireguardAccount($account, false);
            }

            return;
        }

        $this->mikrotikService->enableUser($server, $account->remote_username);
    }

    protected function disableRemoteAccount(Account $account): void
    {
        $server = $account->server;

        if ($server === null) {
            return;
        }

        if ($server->isPasarguard() || $account->service_type->isPasarguard() || $account->pasarguard_user_id) {
            $this->pasarguardService->setUserEnabled($server, (string) $account->remote_username, false);

            return;
        }

        if ($server->isOcserv() || $account->service_type->isOcserv()) {
            $this->ocservService->setUserEnabled($account, false);

            return;
        }

        if ($server->isCiscoAnyconnect() || $account->service_type->isCiscoAnyconnect() || $account->cisco_asa_username) {
            $this->ciscoAnyconnectService->setUserEnabled($account, false);

            return;
        }

        if ($server->isRemnawave() || $account->service_type->isRemnawave() || $account->remnawave_uuid) {
            $uuid = $this->resolveRemnawaveUuid($account);
            if ($uuid !== null) {
                $this->remnawaveService->disablePanelUser($server, $uuid, (string) $account->remote_username);
            }

            return;
        }

        if ($account->service_type->isSanaei() && $account->sanaei_client_uuid) {
            $email = $account->client_email ?? $account->remote_username.'@shahpanel.local';
            $this->sanaeiService->disableAccountClients($server, $email, $account->sanaei_client_uuid, $account->sanaei_inbound_id ?: null);

            return;
        }

        if ($account->service_type === ServiceType::Wireguard && $account->wireguard_public_key) {
            $this->mikrotikService->disablePeer($server, $account->wireguard_public_key);

            return;
        }

        if ($account->service_type->isMikrotik() && filled($account->remote_username)) {
            $this->mikrotikService->disableUser($server, $account->remote_username);
        }
    }

    protected function enableRemoteAccount(Account $account): void
    {
        $server = $account->server;

        if ($server === null) {
            return;
        }

        if ($server->isPasarguard() || $account->service_type->isPasarguard() || $account->pasarguard_user_id) {
            $this->renewPasarguardAccount($account, resetTraffic: false, forceEnable: true);
            $this->ensurePanelQuotaMatchesDatabase($account->fresh(), resetTrafficIfNeeded: false);

            return;
        }

        if ($server->isOcserv() || $account->service_type->isOcserv()) {
            $this->ocservService->setUserEnabled($account, true);

            return;
        }

        if ($server->isCiscoAnyconnect() || $account->service_type->isCiscoAnyconnect() || $account->cisco_asa_username) {
            $this->ciscoAnyconnectService->setUserEnabled($account, true);

            return;
        }

        if ($server->isRemnawave() || $account->service_type->isRemnawave() || $account->remnawave_uuid) {
            $this->renewRemnawaveAccount($account, resetTraffic: false, forceEnable: true);
            $this->ensurePanelQuotaMatchesDatabase($account->fresh(), resetTrafficIfNeeded: false);

            return;
        }

        if ($account->service_type->isSanaei() && $account->sanaei_client_uuid) {
            $email = $account->client_email ?? $account->remote_username.'@shahpanel.local';
            $this->sanaeiService->enableAccountClients($server, $email, $account->sanaei_client_uuid, $account->sanaei_inbound_id ?: null);

            return;
        }

        if ($account->service_type === ServiceType::Wireguard && $account->wireguard_public_key) {
            if ($this->mikrotikService->wireguardPeerExists($server, $account->wireguard_public_key)) {
                $this->mikrotikService->enablePeer($server, $account->wireguard_public_key);
            } else {
                $this->pushWireguardAccount($account, false);
            }

            return;
        }

        $this->mikrotikService->enableUser($server, $account->remote_username);
    }

    protected function removeRemoteAccount(Account $account): void
    {
        $server = $account->server;

        if ($server === null) {
            return;
        }

        $serviceType = $account->service_type;

        if ($server->isPasarguard() || ($serviceType?->isPasarguard() ?? false) || $account->pasarguard_user_id) {
            if (filled($account->remote_username)) {
                $this->pasarguardService->removePanelUser($server, $account->remote_username);
            }

            return;
        }

        if ($server->isOcserv() || ($serviceType?->isOcserv() ?? false)) {
            $username = (string) ($account->remote_username ?? '');
            if ($username !== '') {
                $this->ocservService->removeVpnUser($server, $username);
            }

            return;
        }

        if ($server->isCiscoAnyconnect() || ($serviceType?->isCiscoAnyconnect() ?? false) || $account->cisco_asa_username) {
            $username = (string) ($account->cisco_asa_username ?: $account->remote_username);
            if ($username !== '') {
                $this->ciscoAnyconnectService->removeVpnUser($server, $username);
            }

            return;
        }

        if ($server->isRemnawave() || ($serviceType?->isRemnawave() ?? false) || $account->remnawave_uuid) {
            $uuid = $this->resolveRemnawaveUuid($account);
            if ($uuid !== null) {
                $this->remnawaveService->removePanelUser(
                    $server,
                    $uuid,
                    (string) $account->remote_username,
                );
            }

            return;
        }

        if (($serviceType?->isSanaei() ?? false) && $account->sanaei_client_uuid) {
            $email = $account->client_email ?? $account->remote_username.'@shahpanel.local';
            $this->sanaeiService->deleteAccountClients($server, $email, $account->sanaei_client_uuid);

            return;
        }

        if ($account->wireguard_public_key && ($serviceType === null || $serviceType === ServiceType::Wireguard)) {
            $this->mikrotikService->removePeer($server, $account->wireguard_public_key);

            return;
        }

        if (filled($account->remote_username) && ($serviceType === null || $serviceType->isMikrotik())) {
            $this->mikrotikService->deleteUser($server, $account->remote_username);
        }
    }

    protected function safeRemoteCleanup(Account $account, Server $server): void
    {
        try {
            $this->removeRemoteAccount($account);
            $account->delete();
        } catch (Throwable $exception) {
            Log::warning('Remote cleanup after failed account creation failed', [
                'account_id' => $account->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array{
     *     buyer_charge: string,
     *     buyer_transaction: ?Transaction,
     *     agent_transaction: ?Transaction,
     *     admin_transaction: ?Transaction,
     *     transactions: list<Transaction>
     * }  $purchaseResult
     */
    /**
     * @param  array{
     *     buyer_transaction: ?Transaction,
     *     agent_transaction: ?Transaction,
     *     admin_transaction: ?Transaction
     * }|null  $purchaseResult
     */
    protected function purchaseTransactionsPersisted(?array $purchaseResult): bool
    {
        if ($purchaseResult === null) {
            return false;
        }

        $transaction = $purchaseResult['buyer_transaction'] ?? null;

        if ($transaction === null || $transaction->id === null) {
            return false;
        }

        return Transaction::query()->whereKey($transaction->id)->exists();
    }

    protected function rollbackPurchase(User $buyer, array $purchaseResult, ?int $accountId = null): void
    {
        try {
            DB::transaction(function () use ($buyer, $purchaseResult, $accountId): void {
                if ($accountId !== null) {
                    $this->financialPlanService->restoreForAccount($accountId);
                }

                $currency = $purchaseResult['buyer_transaction']?->currency
                    ?? $purchaseResult['agent_transaction']?->currency
                    ?? $purchaseResult['admin_transaction']?->currency
                    ?? \App\Enums\MoneyCurrency::default()->value;

                $context = [
                    'related_account_id' => $accountId,
                    'description' => 'Rollback failed account provision',
                    'currency' => $currency,
                ];

                if ($purchaseResult['buyer_transaction'] !== null && bccomp($purchaseResult['buyer_charge'], '0', 2) > 0) {
                    $this->walletService->credit(
                        $buyer,
                        $purchaseResult['buyer_charge'],
                        TransactionType::Refund,
                        $context
                    );
                }

                if ($purchaseResult['agent_transaction'] !== null) {
                    $agent = User::query()->find($purchaseResult['agent_transaction']->user_id);
                    if ($agent !== null && bccomp($purchaseResult['agent_margin'] ?? '0', '0', 2) > 0) {
                        $this->walletService->debit(
                            $agent,
                            $purchaseResult['agent_margin'],
                            TransactionType::Refund,
                            $context
                        );
                    }
                }

                if ($purchaseResult['admin_transaction'] !== null) {
                    $admin = User::query()->find($purchaseResult['admin_transaction']->user_id);
                    if ($admin !== null && bccomp($purchaseResult['admin_revenue'] ?? '0', '0', 2) > 0) {
                        $this->walletService->debit(
                            $admin,
                            $purchaseResult['admin_revenue'],
                            TransactionType::Refund,
                            $context
                        );
                    }
                }
            });
        } catch (Throwable $exception) {
            Log::critical('Purchase rollback failed', [
                'buyer_id' => $buyer->id,
                'account_id' => $accountId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array{transactions: list<Transaction>}  $purchaseResult
     */
    protected function linkTieredPurchaseTransactions(array $purchaseResult, int $accountId): void
    {
        $ids = collect($purchaseResult['transactions'] ?? [])
            ->pluck('id')
            ->filter()
            ->all();

        if ($ids === []) {
            return;
        }

        Transaction::query()->whereIn('id', $ids)->update(['related_account_id' => $accountId]);
    }

    /**
     * @param  array{transactions: list<Transaction>}  $purchaseResult
     */
    protected function linkTieredPurchaseTransactionsToInvoice(array $purchaseResult, int $invoiceId): void
    {
        $ids = collect($purchaseResult['transactions'] ?? [])
            ->pluck('id')
            ->filter()
            ->all();

        if ($ids === []) {
            return;
        }

        Transaction::query()->whereIn('id', $ids)->update(['related_invoice_id' => $invoiceId]);
    }
    /**
     * @return array{action: string, message: string}
     */
    protected function pushCiscoAnyconnectAccount(Account $account, bool $onlyMissing = false): array
    {
        $account->loadMissing(['server', 'package']);

        if ($onlyMissing) {
            // ASA has no cheap existence probe for all images — always re-apply.
        }

        $this->ciscoAnyconnectService->syncVpnUser($account, forceEnable: $account->status === AccountStatus::Active);

        return [
            'action' => 'synced',
            'message' => __('services.cisco_user_synced'),
        ];
    }

    protected function renewCiscoAnyconnectAccount(Account $account, bool $resetTraffic = true, bool $forceEnable = false): void
    {
        // Local ASA users have no traffic counter via REST; renew = re-enable + refresh attributes.
        $this->ciscoAnyconnectService->syncVpnUser($account, forceEnable: $forceEnable || true);
    }

    /**
     * @return array{action: string, message: string}
     */
    protected function pushOcservAccount(Account $account, bool $onlyMissing = false): array
    {
        $account->loadMissing(['server', 'package']);

        $this->ocservService->syncVpnUser($account, forceEnable: $account->status === AccountStatus::Active);

        return [
            'action' => 'synced',
            'message' => __('services.ocserv_user_synced'),
        ];
    }

    protected function renewOcservAccount(Account $account, bool $resetTraffic = true, bool $forceEnable = false): void
    {
        $this->ocservService->syncVpnUser($account, forceEnable: $forceEnable || true);
    }

}
