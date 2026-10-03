<?php

namespace App\Services;

use App\Enums\AccountBillingContext;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\PackageDuration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ClientRenewalService
{
    public function __construct(
        protected EndUserService $endUserService,
        protected ClientPortalEconomicsService $clientPortalEconomics,
        protected ClientDisplayPricingService $displayPricingService,
        protected AccountService $accountService,
        protected ActivityLogService $activityLogService,
        protected AccountRenewalPricingService $renewalPricingService,
        protected AccountBillingPackageService $billingPackageService,
        protected PackageCategoryService $packageCategoryService,
    ) {}

    public function renew(Account $account, User $client, ?PackageDuration $duration = null): Account
    {
        if ($client->role !== UserRole::Client) {
            throw new InvalidArgumentException(__('clients.invalid_client'));
        }

        if ((int) $account->client_user_id !== (int) $client->id) {
            throw new InvalidArgumentException(__('clients.account_not_owned'));
        }

        $account->loadMissing(['package', 'packageDuration', 'ownerSeller', 'server']);

        if ($account->package === null
            || ! $this->packageCategoryService->isPackageAvailableForRenewal($account->package)) {
            throw new InvalidArgumentException(__('packages.package_not_available_for_renewal'));
        }

        $account = $this->billingPackageService->syncBillingPackage($account, $duration);
        $owner = $this->endUserService->resolvePortalOwner($client);
        $duration ??= $account->packageDuration;

        if ($duration !== null) {
            $duration = $this->billingPackageService->assertDurationBelongsToBillingPackage($account, $duration);
        }

        if ($duration === null) {
            throw new InvalidArgumentException(__('packages.duration_not_available'));
        }

        // A trial is handed out once; renewing into the test tier would give it
        // again every time it ran out.
        if ($duration->tier->isTest()) {
            throw new InvalidArgumentException(__('packages.test_already_used_for_client'));
        }

        $duration->setRelation('package', $account->package);

        $gb = $this->renewalPricingService->billableDataGb($account);

        $displayUnit = $this->displayPricingService->renewalDisplayUnitPrice($client, $duration);
        $quote = $this->clientPortalEconomics->quote($owner, $duration, $gb, $displayUnit, forRenewal: true);
        $this->clientPortalEconomics->assertCanSettle($client, $owner, $quote);

        return DB::transaction(function () use ($account, $client, $owner, $duration, $quote): Account {
            // Money first: renewing writes the new expiry to the remote panel,
            // which a rollback cannot undo. If the wallets cannot pay, nothing
            // has touched the server yet; if the renewal fails, the debit rolls
            // back with this transaction.
            $this->clientPortalEconomics->settlePurchase($client, $owner, $account, $quote, renewal: true);

            $renewed = $this->accountService->renewAccount(
                $account,
                $duration,
                AccountBillingContext::ClientPortal
            );

            $this->activityLogService->log(
                $client,
                'client.account.renewed',
                $renewed,
                [
                    'display_total' => $quote['display_total'],
                    'wholesale_total' => $quote['wholesale_total'],
                    'retail_profit' => $quote['retail_profit'],
                ]
            );

            return $renewed;
        });
    }
}
