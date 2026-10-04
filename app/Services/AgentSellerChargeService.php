<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * An agent tops up one of their own sellers from the agent's own wallet.
 *
 * The money moves, it is not created: the agent's wallet is debited and the
 * seller's credited in one transaction, so the agent can never hand out more
 * than they hold. Both sides carry the same description naming the agent, the
 * seller and where it was done (panel or bot), so either one's statement
 * explains the line without looking anything else up.
 */
class AgentSellerChargeService
{
    public const FROM_PANEL = 'panel';

    public const FROM_BOT = 'bot';

    public function __construct(protected WalletService $wallets) {}

    public function charge(User $agent, User $seller, string $amount, string $via = self::FROM_PANEL): void
    {
        if ($agent->role !== UserRole::Agent || $seller->role !== UserRole::Seller || (int) $seller->parent_id !== (int) $agent->id) {
            throw new InvalidArgumentException(__('wallet.seller_charge_not_yours'));
        }

        $amount = number_format((float) $amount, 2, '.', '');

        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException(__('wallet.seller_charge_amount'));
        }

        $description = __('wallet.seller_charge_line', [
            'agent' => $agent->username,
            'seller' => $seller->username,
            'via' => __('wallet.via_'.$via),
        ], 'fa');

        DB::transaction(function () use ($agent, $seller, $amount, $description): void {
            try {
                $this->wallets->debit($agent, $amount, TransactionType::Charge, [
                    'description' => $description, 'source_user_id' => $seller->id,
                ]);
            } catch (InsufficientWalletBalanceException) {
                throw new InvalidArgumentException(__('wallet.seller_charge_low_balance'));
            }

            $this->wallets->credit($seller, $amount, TransactionType::Charge, [
                'description' => $description, 'source_user_id' => $agent->id,
            ]);
        });
    }
}
