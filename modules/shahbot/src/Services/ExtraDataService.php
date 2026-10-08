<?php

namespace Modules\ShahBot\Services;

use App\Enums\AccountStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Services\AccountService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotSettings;

/**
 * More data on a service the customer already has, without renewing it: the
 * expiry stays, the used data stays, the limit grows.
 *
 * Only in the main bot. In an agent's bot the customer's wallet is credit the
 * agent sold, and an add-on there would hand out the admin's servers without
 * the agent paying for them.
 */
class ExtraDataService
{
    public function __construct(
        protected BotSettings $settings,
        protected BotUserService $users,
        protected WalletService $wallets,
        protected AccountService $accounts,
    ) {}

    public function pricePerGb(): float
    {
        return max(0.0, (float) $this->settings->get('extra_gb_price'));
    }

    /**
     * @return list<int>
     */
    public function sizes(): array
    {
        $sizes = array_map('intval', preg_split('/[\s,،]+/u', western_digits((string) $this->settings->get('extra_gb_options')), -1, PREG_SPLIT_NO_EMPTY));

        return array_values(array_unique(array_filter($sizes, fn (int $gb): bool => $gb > 0 && $gb <= 1000)));
    }

    public function available(BotUser $user, Account $account): bool
    {
        return (int) $user->bot_id === 0
            && $this->pricePerGb() > 0
            && $this->sizes() !== []
            && (int) $account->data_limit_bytes > 0
            && $account->refunded_at === null
            && in_array($account->status, [AccountStatus::Active, AccountStatus::Exhausted], true)
            && ($account->expiry_at === null || $account->expiry_at->isFuture())
            && ! $account->packageDuration?->tier->isTest();
    }

    public function price(int $gb): string
    {
        return number_format($gb * $this->pricePerGb(), 2, '.', '');
    }

    public function buy(BotUser $user, Account $account, int $gb): Account
    {
        if (! $this->available($user, $account) || ! in_array($gb, $this->sizes(), true)) {
            throw new InvalidArgumentException(__('shahbot::bot.product_unavailable'));
        }

        $client = $this->users->client($user);
        $price = $this->price($gb);

        // The charge and the bigger limit stand or fall together.
        return DB::transaction(function () use ($client, $account, $gb, $price): Account {
            $this->wallets->assertSufficientBalance($client, $price);
            $this->wallets->debit($client, $price, TransactionType::Purchase, [
                'description' => __('shahbot::bot.extra_gb_tx', ['gb' => $gb, 'name' => (string) ($account->display_label ?: $account->remote_username)]),
                'account_id' => $account->id,
            ]);

            return $this->accounts->applyVolumeTopUp($account, (float) $gb, syncRemote: true);
        });
    }
}
