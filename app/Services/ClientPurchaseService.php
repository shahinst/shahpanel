<?php

namespace App\Services;

use App\Enums\AccountBillingContext;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ClientPurchaseService
{
    public function __construct(
        protected EndUserService $endUserService,
        protected ClientDisplayPricingService $displayPricingService,
        protected ClientPortalEconomicsService $clientPortalEconomics,
        protected AccountService $accountService,
        protected ServerSelectionService $serverSelection,
        protected PackageService $packageService,
        protected ActivityLogService $activityLogService,
        protected TestPackageGuardService $testPackageGuardService,
    ) {}

    public function purchase(User $client, Package $package, PackageDuration $duration, ?float $gb = null): Account
    {
        if ($client->role !== UserRole::Client) {
            throw new InvalidArgumentException(__('clients.invalid_client'));
        }

        if ($package->kyc_required) {
            throw new InvalidArgumentException(__('kyc.client_purchase_blocked'));
        }

        $owner = $this->endUserService->resolvePortalOwner($client);

        if (! in_array($owner->role, [UserRole::Agent, UserRole::Seller], true)) {
            throw new InvalidArgumentException(__('clients.purchase_owner_invalid'));
        }

        app(UserPackageAssignmentService::class)->assertUserHasPackage($owner, $package);
        $duration = $this->packageService->resolveDuration($package, $duration->id);
        $duration->setRelation('package', $package);
        $this->testPackageGuardService->assertClientCanReceiveTest($client, $package, $duration);

        if ($package->isElastic()) {
            if ($gb === null || $gb <= 0) {
                throw new InvalidArgumentException(__('packages.elastic_gb_required'));
            }
            $gb = $package->clampDataGb($gb);
        } else {
            $gb = null;
        }

        $catalog = $this->displayPricingService->catalogForClient($client);
        $row = $catalog->first(fn (array $item): bool => (int) $item['duration']->id === (int) $duration->id);

        if ($row === null) {
            throw new InvalidArgumentException(__('packages.duration_not_available'));
        }

        $quote = $this->clientPortalEconomics->quote($owner, $duration, $gb, $row['display_price']);
        $this->clientPortalEconomics->assertCanSettle($client, $owner, $quote);

        $server = $this->serverSelection->pickLeastBusyForPackage($package);

        return DB::transaction(function () use ($client, $owner, $package, $duration, $server, $quote, $gb): Account {
            $account = $this->accountService->createAccount(
                $owner,
                $package,
                $server,
                $duration,
                [
                    'client_mode' => 'existing',
                    'client_user_id' => $client->id,
                    'data_gb' => $gb,
                ],
                AccountBillingContext::ClientPortal
            );

            $this->clientPortalEconomics->settlePurchase($client, $owner, $account, $quote);

            $this->activityLogService->log(
                $client,
                'client.account.purchased',
                $account,
                [
                    'display_total' => $quote['display_total'],
                    'wholesale_total' => $quote['wholesale_total'],
                    'retail_profit' => $quote['retail_profit'],
                ]
            );

            return $account;
        });
    }
}
