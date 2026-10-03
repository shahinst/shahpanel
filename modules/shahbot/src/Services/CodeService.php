<?php

namespace Modules\ShahBot\Services;

use App\Enums\TransactionType;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotCode;
use Modules\ShahBot\Models\BotCodeUse;
use Modules\ShahBot\Models\BotOrder;
use Modules\ShahBot\Models\BotUser;

/**
 * Discount and gift codes. Both are paid for by the bot's owner: the value
 * moves from the owner's wallet to the buyer's, so the panel's books stay
 * balanced and the admin is never charged for a seller's promotion.
 */
class CodeService
{
    public function __construct(
        protected BotUserService $users,
        protected WalletService $wallets,
    ) {}

    public static function normalize(string $code): string
    {
        return mb_strtoupper(trim(western_digits($code)));
    }

    /**
     * Validates a discount code for a purchase of $amount and returns the code
     * with the amount it takes off.
     *
     * @return array{code: BotCode, discount: string}
     */
    public function discountFor(BotUser $user, string $input, string $amount): array
    {
        $code = BotCode::query()
            ->where('kind', BotCode::DISCOUNT)
            ->where('code', self::normalize($input))
            ->first();

        if ($code === null || ! $code->isUsable()) {
            throw new InvalidArgumentException(__('shahbot::bot.code_invalid'));
        }

        if ($code->uses()->where('bot_user_id', $user->id)->exists()) {
            throw new InvalidArgumentException(__('shahbot::bot.code_used'));
        }

        if ($code->min_amount !== null && (float) $amount < (float) $code->min_amount) {
            throw new InvalidArgumentException(__('shahbot::bot.code_min_amount', ['amount' => format_money($code->min_amount)]));
        }

        if ($code->first_purchase_only && BotOrder::query()->where('bot_user_id', $user->id)->where('type', 'buy')->exists()) {
            throw new InvalidArgumentException(__('shahbot::bot.code_first_only'));
        }

        $discount = $code->value_type === 'percent'
            ? round((float) $amount * min(100, (float) $code->value) / 100, 2)
            : min((float) $amount, (float) $code->value);

        return ['code' => $code, 'discount' => number_format($discount, 2, '.', '')];
    }

    /**
     * Records the use of a discount code inside the purchase transaction and
     * funds it from the owner. Throws when the code ran out in the meantime.
     */
    public function consumeDiscount(BotUser $user, BotCode $code, string $discount): void
    {
        $locked = BotCode::query()->whereKey($code->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isUsable() || $locked->uses()->where('bot_user_id', $user->id)->exists()) {
            throw new InvalidArgumentException(__('shahbot::bot.code_invalid'));
        }

        BotCodeUse::query()->create(['code_id' => $locked->id, 'bot_user_id' => $user->id, 'amount' => $discount]);
        $locked->increment('used_count');

        $this->transfer($user, $discount, 'Bot discount code '.$locked->code);
    }

    /**
     * Credits a gift code to the user's wallet. Returns the amount credited.
     */
    public function redeemGift(BotUser $user, string $input): string
    {
        return DB::transaction(function () use ($user, $input): string {
            $code = BotCode::query()
                ->where('kind', BotCode::GIFT)
                ->where('code', self::normalize($input))
                ->lockForUpdate()
                ->first();

            if ($code === null || ! $code->isUsable()) {
                throw new InvalidArgumentException(__('shahbot::bot.code_invalid'));
            }

            if ($code->uses()->where('bot_user_id', $user->id)->exists()) {
                throw new InvalidArgumentException(__('shahbot::bot.code_used'));
            }

            $amount = number_format((float) $code->value, 2, '.', '');

            BotCodeUse::query()->create(['code_id' => $code->id, 'bot_user_id' => $user->id, 'amount' => $amount]);
            $code->increment('used_count');

            $this->transfer($user, $amount, 'Bot gift code '.$code->code);

            return $amount;
        });
    }

    /**
     * Moves $amount from the owner's wallet to the bot user's. The owner may
     * go negative: the promotion was promised, and the owner settles with the
     * admin like any other balance.
     */
    public function transfer(BotUser $user, string $amount, string $description): void
    {
        if ((float) $amount <= 0) {
            return;
        }

        $client = $this->users->client($user);
        $owner = $this->users->owner();
        $context = ['description' => $description, 'source_user_id' => $client->id];

        $this->wallets->debit($owner, $amount, TransactionType::Adjustment, $context, allowNegative: true);
        $this->wallets->credit($client, $amount, TransactionType::Adjustment, array_merge($context, ['source_user_id' => $owner->id]));
    }
}
