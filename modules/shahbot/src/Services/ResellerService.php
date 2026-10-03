<?php

namespace Modules\ShahBot\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\PackageDuration;
use App\Models\User;
use App\Services\AccountService;
use App\Services\PackageCategoryService;
use App\Services\ServerSelectionService;
use App\Services\UserPackageAssignmentService;
use App\Services\UserPackagePricingService;
use App\Services\WalletService;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotOrder;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotSettings;
use Throwable;

/**
 * The reseller side of the bot: a bot user who is a seller of the panel buys
 * at their wholesale price from their own panel wallet, one account or many
 * at once, exactly like creating accounts in the seller panel.
 */
class ResellerService
{
    public function __construct(
        protected BotSettings $settings,
        protected UserPackagePricingService $pricing,
        protected WalletService $wallets,
        protected PackageCategoryService $categories,
    ) {}

    public function seller(BotUser $user): ?User
    {
        $seller = $user->reseller_user_id ? $user->reseller : null;

        return $seller !== null && $seller->role === UserRole::Seller && $seller->status === UserStatus::Active ? $seller : null;
    }

    public function requireSeller(BotUser $user): User
    {
        return $this->seller($user) ?? throw new InvalidArgumentException(__('shahbot::bot.reseller_only'));
    }

    public function balance(User $seller): string
    {
        return number_format((float) $this->wallets->getOrCreateWallet($seller)->fresh()->balance, 2, '.', '');
    }

    /**
     * Durations the seller may buy, with the wholesale unit price.
     *
     * @return Collection<int, array{duration: PackageDuration, price: ?string}>
     */
    public function catalog(User $seller): Collection
    {
        $packageIds = app(UserPackageAssignmentService::class)->assignedPackageIds($seller);

        return PackageDuration::query()
            ->with('package')
            ->whereIn('package_id', $packageIds ?: [0])
            ->where('is_enabled', true)
            ->whereHas('package', fn ($q) => $q->where('is_active', true)->where('kyc_required', false))
            ->orderBy('package_id')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (PackageDuration $d) => ! $d->tier->isTest() && $this->categories->isPackageAvailableForNewAccounts($d->package))
            ->map(fn (PackageDuration $d) => ['duration' => $d, 'price' => $this->unitPrice($seller, $d, $d->package->isElastic() ? 1.0 : null)])
            ->filter(fn (array $row) => $row['price'] !== null)
            ->values();
    }

    public function unitPrice(User $seller, PackageDuration $duration, ?float $gb = null): ?string
    {
        try {
            return $this->pricing->lineTotal($seller, $duration, $gb);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Creates $quantity accounts. Stops at the first failure and returns what
     * was made so far together with the error.
     *
     * @return array{accounts: list<Account>, error: ?string}
     */
    public function bulkBuy(BotUser $user, int $durationId, int $quantity, ?float $gb = null): array
    {
        $seller = $this->requireSeller($user);
        $max = max(1, (int) $this->settings->get('bulk_max'));

        if ($quantity < 1 || $quantity > $max) {
            throw new InvalidArgumentException(__('shahbot::bot.bulk_range', ['max' => persian_digits($max)]));
        }

        $row = $this->catalog($seller)->first(fn (array $r) => (int) $r['duration']->id === $durationId);

        if ($row === null) {
            throw new InvalidArgumentException(__('shahbot::bot.product_unavailable'));
        }

        $duration = $row['duration'];
        $package = $duration->package;
        $gb = $package->isElastic() ? $package->clampDataGb((float) ($gb ?: $package->min_data_gb ?: 1)) : null;
        $unit = $this->unitPrice($seller, $duration, $gb) ?? '0';

        if ((float) $this->balance($seller) < (float) $unit * $quantity) {
            throw new InvalidArgumentException(__('shahbot::bot.balance_low'));
        }

        $accounts = [];
        $error = null;

        for ($i = 0; $i < $quantity; $i++) {
            try {
                $server = app(ServerSelectionService::class)->pickLeastBusyForPackage($package);
                $account = app(AccountService::class)->createAccount($seller, $package, $server, $duration, array_filter([
                    'skip_portal_client' => true,
                    'auto_random_remote_identity' => true,
                    'data_gb' => $gb,
                ], fn ($v) => $v !== null));

                BotOrder::query()->create([
                    'bot_user_id' => $user->id,
                    'account_id' => $account->id,
                    'package_duration_id' => $duration->id,
                    'type' => 'bulk',
                    'amount' => $unit,
                    'data_gb' => $gb,
                ]);

                $accounts[] = $account;
            } catch (Throwable $e) {
                report($e);
                $error = $e->getMessage();

                break;
            }
        }

        return ['accounts' => $accounts, 'error' => $error];
    }

    /**
     * @return Collection<int, Account>
     */
    public function accounts(User $seller, int $limit = 20): Collection
    {
        return Account::query()
            ->where('owner_seller_id', $seller->id)
            ->whereNull('refunded_at')
            ->with('package')
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
