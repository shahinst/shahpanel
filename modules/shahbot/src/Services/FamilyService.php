<?php

namespace Modules\ShahBot\Services;

use App\Models\Account;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\ShahBot\Models\AccountShare;
use Modules\ShahBot\Models\BotUser;

/**
 * Family sharing. The buyer of an account invites family members with a
 * one-time link; each member then finds the account under "family services"
 * and can fetch its config, while renewing, refunding and handing it on stay
 * with the buyer. The buyer sees who has it and can take it back at any time.
 *
 * Sharing never moves the account: the buyer's own list, wallet and limits
 * are untouched, and the account's connection limit still applies to all.
 */
class FamilyService
{
    public const MAX_MEMBERS = 5;

    public const INVITE_HOURS = 24;

    public function __construct(protected ShopService $shop) {}

    /**
     * A start parameter for a link that adds its opener to the account.
     */
    public function invite(BotUser $owner, Account $account): string
    {
        $this->shop->assertOwnsAccount($owner, $account);

        if ($this->members($account)->count() >= self::MAX_MEMBERS) {
            throw new InvalidArgumentException(__('shahbot::bot.family_full', ['max' => persian_digits(self::MAX_MEMBERS)]));
        }

        $token = Str::lower(Str::random(24));
        Cache::put('shahbot:family:'.$token, ['account' => $account->id, 'owner' => $owner->id], now()->addHours(self::INVITE_HOURS));

        return 'fam_'.$token;
    }

    /**
     * Uses an invite; the link works once and only inside the same bot.
     */
    public function join(BotUser $member, string $token): AccountShare
    {
        $invite = Cache::pull('shahbot:family:'.$token);
        $owner = $invite !== null ? BotUser::query()->find($invite['owner']) : null;
        $account = $invite !== null ? Account::query()->find($invite['account']) : null;

        if ($owner === null || $account === null || (int) $owner->bot_id !== (int) $member->bot_id) {
            throw new InvalidArgumentException(__('shahbot::bot.family_invite_invalid'));
        }

        if ((int) $owner->id === (int) $member->id || ($member->client_user_id !== null && (int) $member->client_user_id === (int) $account->client_user_id)) {
            throw new InvalidArgumentException(__('shahbot::bot.family_own_account'));
        }

        $this->shop->assertOwnsAccount($owner, $account);

        if ($this->members($account)->count() >= self::MAX_MEMBERS) {
            throw new InvalidArgumentException(__('shahbot::bot.family_full', ['max' => persian_digits(self::MAX_MEMBERS)]));
        }

        return AccountShare::query()->firstOrCreate(
            ['account_id' => $account->id, 'member_bot_user_id' => $member->id],
            ['owner_bot_user_id' => $owner->id],
        );
    }

    /**
     * @return Collection<int, AccountShare>
     */
    public function members(Account $account): Collection
    {
        return AccountShare::query()->with('member')->where('account_id', $account->id)->get();
    }

    /**
     * Accounts shared with this user that their buyer still holds.
     *
     * @return Collection<int, Account>
     */
    public function sharedWith(BotUser $member): Collection
    {
        return AccountShare::query()
            ->with(['account.package', 'account.packageDuration', 'account.server', 'owner'])
            ->where('member_bot_user_id', $member->id)
            ->get()
            ->filter(fn (AccountShare $share): bool => $share->account !== null && $share->account->refunded_at === null
                && $share->owner !== null && (int) $share->account->client_user_id === (int) $share->owner->client_user_id)
            ->map(fn (AccountShare $share): Account => $share->account)
            ->values();
    }

    public function assertShared(BotUser $member, Account $account): void
    {
        if (! $this->sharedWith($member)->contains(fn (Account $a): bool => (int) $a->id === (int) $account->id)) {
            throw new InvalidArgumentException(__('shahbot::bot.service_not_found'));
        }
    }

    public function remove(BotUser $owner, Account $account, int $shareId): void
    {
        $this->shop->assertOwnsAccount($owner, $account);
        AccountShare::query()->where('account_id', $account->id)->whereKey($shareId)->delete();
    }
}
