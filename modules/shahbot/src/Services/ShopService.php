<?php

namespace Modules\ShahBot\Services;

use App\Models\Account;
use App\Models\PackageDuration;
use App\Services\AccountRenewalPricingService;
use App\Services\ClientDisplayPricingService;
use App\Services\ClientPortalEconomicsService;
use App\Services\ClientPurchaseService;
use App\Services\ClientRenewalService;
use App\Services\PackageCategoryService;
use App\Services\WalletService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotCode;
use Modules\ShahBot\Models\BotOrder;
use Modules\ShahBot\Models\BotPackage;
use Modules\ShahBot\Models\BotReferralReward;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotSettings;

/**
 * The store: what a bot user can buy, what it costs, and the purchase itself.
 * Prices and stock come from the owner's client catalog (the same one the
 * client portal shows), so the bot never sells at a price the panel would not.
 */
class ShopService
{
    public function __construct(
        protected BotSettings $settings,
        protected BotUserService $users,
        protected CodeService $codes,
        protected ClientDisplayPricingService $pricing,
        protected ClientPortalEconomicsService $economics,
        protected ClientPurchaseService $purchases,
        protected ClientRenewalService $renewals,
        protected PackageCategoryService $categories,
        protected WalletService $wallets,
        protected BotNotifier $notifier,
    ) {}

    /**
     * Sellable rows (no test tiers), grouped by package category.
     *
     * @return Collection<int, array{label: string, rows: Collection}>
     */
    public function groups(BotUser $user): Collection
    {
        $rows = $this->catalog($user)
            ->filter(fn (array $row): bool => ! $row['duration']->tier->isTest()
                && $this->categories->isPackageAvailableForNewAccounts($row['package']))
            ->values();

        return $this->categories->groupCatalogRows($rows)
            ->filter(fn (array $group): bool => $group['rows']->isNotEmpty())
            ->values();
    }

    public function row(BotUser $user, int $durationId): ?array
    {
        return $this->catalog($user)
            ->first(fn (array $row): bool => (int) $row['duration']->id === $durationId);
    }

    /**
     * The owner's catalog, narrowed to what this particular bot sells.
     *
     * An agent bot shows a subset of its owner's catalog at prices the owner
     * chose. The narrowing happens here, in the one place both the menu and
     * the purchase read, so a tariff the owner switched off cannot be bought
     * by replaying its callback -- a filter applied only where the buttons are
     * drawn would stop the menu and nothing else.
     *
     * A bot with no rows of its own sells the whole catalog, so bots that
     * existed before this table keep behaving exactly as they did.
     *
     * @return Collection<int, array>
     */
    protected function catalog(BotUser $user): Collection
    {
        $rows = $this->pricing->catalogForClient($this->users->client($user));
        $botId = (int) $user->bot_id;

        if ($botId === 0) {
            return $rows;
        }

        $own = BotPackage::query()->where('bot_id', $botId)->get()->keyBy('package_duration_id');

        if ($own->isEmpty()) {
            return $rows;
        }

        return $rows->filter(function (array $row) use ($own): bool {
            $pick = $own->get((int) $row['duration']->id);

            return $pick === null || $pick->is_enabled;
        })->map(function (array $row) use ($own): array {
            $pick = $own->get((int) $row['duration']->id);

            // The owner may raise the price, never drop it below what the
            // panel charges them -- a cheaper bot price would be paid out of
            // the owner's own wallet on every sale. The floor is enforced when
            // the price is saved as well; this is the second line, for a row
            // that was already in the table when the owner's own price rose.
            if ($pick !== null && $pick->display_price !== null
                && bccomp((string) $pick->display_price, (string) $row['display_price'], 2) >= 0) {
                $row['display_price'] = (string) $pick->display_price;
            }

            return $row;
        })->values();
    }

    public function rowLabel(array $row): string
    {
        $package = $row['package'];
        $label = $package->name.' · '.$row['duration']->tier->label();

        if ($package->isElastic()) {
            return $label.' · '.__('shahbot::bot.per_gb', ['price' => format_money($row['display_price'])]);
        }

        return $label.' · '.format_money($row['display_price']);
    }

    /**
     * @return array{row: array, total: string, discount: string, payable: string, code: ?BotCode, gb: ?float}
     */
    public function quote(BotUser $user, int $durationId, ?float $gb = null, ?string $code = null): array
    {
        $row = $this->row($user, $durationId);

        if ($row === null) {
            throw new InvalidArgumentException(__('shahbot::bot.product_unavailable'));
        }

        $package = $row['package'];

        if ($package->isElastic()) {
            if ($gb === null || $gb <= 0) {
                throw new InvalidArgumentException(__('shahbot::bot.gb_required'));
            }
            $gb = $package->clampDataGb($gb);
        } else {
            $gb = null;
        }

        $owner = $this->users->owner($user);
        $quote = $this->economics->quote($owner, $row['duration'], $gb, $row['display_price']);
        $total = $quote['display_total'];
        $discount = '0.00';
        $codeModel = null;

        if ($code !== null && $code !== '') {
            ['code' => $codeModel, 'discount' => $discount] = $this->codes->discountFor($user, $code, $total);
        }

        // The loyalty discount comes on top, on what is left after the code.
        $loyalty = app(LoyaltyService::class)->discount($user, number_format(max(0, (float) $total - (float) $discount), 2, '.', ''));

        return [
            'row' => $row,
            'total' => $total,
            'discount' => $discount,
            'loyalty' => $loyalty,
            'payable' => number_format(max(0, (float) $total - (float) $discount - (float) $loyalty), 2, '.', ''),
            'code' => $codeModel,
            'gb' => $gb,
        ];
    }

    /**
     * Buys a service from the wallet. The discount is moved into the wallet in
     * the same transaction, so a failed purchase takes it back with everything
     * else.
     */
    public function purchase(BotUser $user, int $durationId, ?float $gb = null, ?string $code = null, ?string $label = null): BotOrder
    {
        $this->assertSalesOpen();
        $quote = $this->quote($user, $durationId, $gb, $code);
        $client = $this->users->client($user);

        if ((float) $this->users->balance($user) < (float) $quote['payable']) {
            throw new InvalidArgumentException(__('shahbot::bot.balance_low'));
        }

        $order = DB::transaction(function () use ($user, $client, $quote, $label): BotOrder {
            if ($quote['code'] !== null && (float) $quote['discount'] > 0) {
                $this->codes->consumeDiscount($user, $quote['code'], $quote['discount']);
            }

            if ((float) $quote['loyalty'] > 0) {
                $this->codes->transfer($user, $quote['loyalty'], 'Bot loyalty discount');
            }

            $account = $this->purchases->purchase($client, $quote['row']['package'], $quote['row']['duration'], $quote['gb']);

            // The name the buyer chose is the account's label in the panel and
            // the bot; the remote username stays the panel's own, so a name
            // can never collide with or impersonate another account.
            if ($label !== null && trim($label) !== '') {
                $account->forceFill(['display_label' => mb_substr(trim($label), 0, 60)])->save();
            }

            return BotOrder::query()->create([
                'bot_user_id' => $user->id,
                'account_id' => $account->id,
                'package_duration_id' => $quote['row']['duration']->id,
                'type' => 'buy',
                'amount' => $quote['payable'],
                'discount' => number_format((float) $quote['discount'] + (float) $quote['loyalty'], 2, '.', ''),
                'discount_code' => $quote['code']?->code,
                'data_gb' => $quote['gb'],
            ]);
        });

        $this->rewardReferrer($user, $order);
        $this->announceSale($user, $order);

        return $order;
    }

    /**
     * One free (or cheap) test service per Telegram user.
     */
    /**
     * Whether the trial must wait for the user's phone number.
     */
    public function trialNeedsPhone(BotUser $user): bool
    {
        return $this->settings->bool('test_requires_phone') && blank($user->phone);
    }

    /**
     * One trial per person, not per bot user: the same Telegram account in
     * another agent's bot, or another Telegram account with the same phone,
     * already had it. Both cost the same servers.
     */
    public function trialTakenElsewhere(BotUser $user): bool
    {
        return BotUser::query()
            ->whereKeyNot($user->id)
            ->whereNotNull('test_used_at')
            ->where(function ($query) use ($user): void {
                $query->where('telegram_id', $user->telegram_id);

                if (filled($user->phone)) {
                    $query->orWhere('phone', $user->phone);
                }
            })
            ->exists();
    }

    public function trial(BotUser $user): BotOrder
    {
        if ($this->trialNeedsPhone($user)) {
            throw new InvalidArgumentException(__('shahbot::bot.test_phone_first'));
        }

        if (! $this->settings->bool('test_enabled')) {
            throw new InvalidArgumentException(__('shahbot::bot.test_disabled'));
        }

        if ($user->test_used_at !== null || $this->trialTakenElsewhere($user)) {
            throw new InvalidArgumentException(__('shahbot::bot.test_used'));
        }

        $duration = PackageDuration::query()->with('package')->find($this->settings->int('test_duration_id'));

        if ($duration === null || $duration->package === null) {
            throw new InvalidArgumentException(__('shahbot::bot.test_disabled'));
        }

        $client = $this->users->client($user);
        $gb = $duration->package->isElastic() ? (float) ($duration->package->min_data_gb ?: 1) : null;

        $order = DB::transaction(function () use ($user, $client, $duration, $gb): BotOrder {
            $locked = BotUser::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($locked?->test_used_at !== null) {
                throw new InvalidArgumentException(__('shahbot::bot.test_used'));
            }

            $account = $this->purchases->purchase($client, $duration->package, $duration, $gb);
            $user->forceFill(['test_used_at' => now()])->save();

            return BotOrder::query()->create([
                'bot_user_id' => $user->id,
                'account_id' => $account->id,
                'package_duration_id' => $duration->id,
                'type' => 'test',
                'amount' => 0,
                'data_gb' => $gb,
            ]);
        });

        return $order;
    }

    /**
     * Durations the account can be renewed into, with their price.
     *
     * @return Collection<int, array{duration: PackageDuration, price: string}>
     */
    public function renewalOptions(BotUser $user, Account $account): Collection
    {
        $account->loadMissing('package');
        $client = $this->users->client($user);
        $owner = $this->users->owner($user);

        return $this->pricing->catalogForClient($client)
            ->filter(fn (array $row): bool => (int) $row['package']->id === (int) $account->package_id
                && ! $row['duration']->tier->isTest())
            ->map(function (array $row) use ($client, $owner, $account): ?array {
                try {
                    $unit = $this->pricing->renewalDisplayUnitPrice($client, $row['duration']);
                    $gb = app(AccountRenewalPricingService::class)->billableDataGb($account);
                    $quote = $this->economics->quote($owner, $row['duration'], $gb, $unit, forRenewal: true);

                    return ['duration' => $row['duration'], 'price' => $quote['display_total']];
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter()
            ->values();
    }

    public function renew(BotUser $user, Account $account, int $durationId): BotOrder
    {
        if (! $this->settings->bool('renew_enabled')) {
            throw new InvalidArgumentException(__('shahbot::bot.renew_disabled'));
        }

        $this->assertOwnsAccount($user, $account);

        $option = $this->renewalOptions($user, $account)
            ->first(fn (array $o): bool => (int) $o['duration']->id === $durationId);

        if ($option === null) {
            throw new InvalidArgumentException(__('shahbot::bot.product_unavailable'));
        }

        if ((float) $this->users->balance($user) < (float) $option['price']) {
            throw new InvalidArgumentException(__('shahbot::bot.balance_low'));
        }

        $this->renewals->renew($account, $this->users->client($user), $option['duration']);

        $order = BotOrder::query()->create([
            'bot_user_id' => $user->id,
            'account_id' => $account->id,
            'package_duration_id' => $option['duration']->id,
            'type' => 'renew',
            'amount' => $option['price'],
        ]);

        $this->rewardReferrer($user, $order);
        $this->announceSale($user, $order);

        return $order;
    }

    public function assertOwnsAccount(BotUser $user, Account $account): void
    {
        if ($user->client_user_id === null || (int) $account->client_user_id !== (int) $user->client_user_id) {
            throw new InvalidArgumentException(__('shahbot::bot.service_not_found'));
        }
    }

    /**
     * @return Collection<int, Account>
     */
    public function accounts(BotUser $user): Collection
    {
        if ($user->client_user_id === null) {
            return collect();
        }

        return Account::query()
            ->where('client_user_id', $user->client_user_id)
            ->whereNull('refunded_at')
            ->with(['package', 'packageDuration', 'server'])
            ->latest('id')
            ->get();
    }

    protected function assertSalesOpen(): void
    {
        if (! $this->settings->bool('sales_enabled')) {
            throw new InvalidArgumentException($this->settings->localized('closed_text') ?: __('shahbot::bot.sales_closed'));
        }
    }

    /**
     * Pays the inviter their share of a paid order, funded by the owner.
     */
    protected function rewardReferrer(BotUser $user, BotOrder $order): void
    {
        if (! $this->settings->bool('referral_enabled') || $user->referrer_id === null || (float) $order->amount <= 0) {
            return;
        }

        $percent = max(0, min(100, (float) $this->settings->get('referral_percent')));
        $referrer = $user->referrer;

        if ($percent <= 0 || $referrer === null || $referrer->is_blocked) {
            return;
        }

        if ($this->settings->bool('referral_first_only')
            && BotReferralReward::query()->where('referrer_id', $referrer->id)->where('referred_id', $user->id)->exists()) {
            return;
        }

        $amount = number_format(round((float) $order->amount * $percent / 100, 2), 2, '.', '');

        if ((float) $amount <= 0) {
            return;
        }

        try {
            DB::transaction(function () use ($referrer, $user, $order, $amount): void {
                $this->codes->transfer($referrer, $amount, 'Bot referral reward');
                BotReferralReward::query()->create([
                    'referrer_id' => $referrer->id,
                    'referred_id' => $user->id,
                    'order_id' => $order->id,
                    'amount' => $amount,
                ]);
            });

            $this->notifier->user($referrer, fn () => __('shahbot::bot.referral_rewarded', ['amount' => format_money($amount)]));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function announceSale(BotUser $user, BotOrder $order): void
    {
        $order->loadMissing(['account', 'duration.package']);

        $this->notifier->admins(__('shahbot::bot.admin_new_sale', [
            'type' => __('shahbot::bot.order_type_'.$order->type),
            'user' => e($user->displayName()),
            'id' => $user->telegram_id,
            'service' => e(($order->duration?->package?->name ?? '—').' · '.($order->duration?->tier->label() ?? '')),
            'username' => e((string) $order->account?->remote_username),
            'amount' => format_money($order->amount),
        ]));
    }
}
