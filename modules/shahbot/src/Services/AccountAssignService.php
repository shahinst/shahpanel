<?php

namespace Modules\ShahBot\Services;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\ShahBot\Models\AccountAssignment;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotContext;
use Modules\ShahBot\Support\BotSettings;

/**
 * Hands one of a reseller's accounts to someone who started their bot, and
 * takes it back when it went to the wrong person.
 *
 * Every check is made against the owner passed in, never against what the
 * request names: the account must sit in the owner's own tree and the member
 * must belong to the owner's own bot. The previous owner of the account is
 * kept with the assignment, so taking it back puts the account exactly where
 * it was before.
 */
class AccountAssignService
{
    public function __construct(
        protected BotUserService $users,
        protected BotNotifier $notifier,
        protected BotContext $context,
    ) {}

    /**
     * Find a member of the bot by Telegram id or @username.
     */
    public function findMember(?int $botId, string $needle): ?BotUser
    {
        $needle = ltrim(trim(western_digits($needle)), '@');

        if ($needle === '') {
            return null;
        }

        return BotUser::query()
            ->where(fn ($q) => (int) $botId > 0 ? $q->where('bot_id', $botId) : $q->whereNull('bot_id')->orWhere('bot_id', 0))
            ->where(fn ($q) => $q->where('telegram_id', ctype_digit($needle) ? (int) $needle : -1)->orWhere('username', $needle))
            ->first();
    }

    /**
     * @return bool whether the member was told in Telegram
     */
    public function assign(User $owner, ?int $botId, Account $account, BotUser $member, ?User $actor = null): bool
    {
        if ((int) $member->bot_id !== (int) $botId) {
            throw new InvalidArgumentException(__('shahbot::bot.give_user_not_found'));
        }

        if (! Account::query()->ownedByHierarchy($owner)->whereKey($account->id)->exists()) {
            throw new InvalidArgumentException(__('shahbot::admin.assign_not_yours'));
        }

        DB::transaction(function () use ($botId, $account, $member, $actor): void {
            $locked = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if (AccountAssignment::query()->active()->where('account_id', $locked->id)->exists()) {
                throw new InvalidArgumentException(__('shahbot::admin.assign_already'));
            }

            $client = $this->users->client($member);

            AccountAssignment::query()->create([
                'bot_id' => $botId,
                'account_id' => $locked->id,
                'bot_user_id' => $member->id,
                'previous_client_user_id' => $locked->client_user_id,
                'client_user_id' => $client->id,
                'assigned_by_user_id' => $actor?->id,
            ]);

            $locked->forceFill(['client_user_id' => $client->id])->save();
        });

        $name = $account->display_label ?: $account->remote_username;

        return $this->notifier->user($member, fn () => __('shahbot::bot.give_received', [
            'bot' => e($this->brand($botId)),
            'account' => e($name),
        ]));
    }

    public function revoke(User $owner, AccountAssignment $assignment): void
    {
        if ($assignment->revoked_at !== null
            || ! Account::query()->ownedByHierarchy($owner)->whereKey($assignment->account_id)->exists()) {
            throw new InvalidArgumentException(__('shahbot::admin.assign_not_yours'));
        }

        $account = DB::transaction(function () use ($assignment): Account {
            $account = Account::query()->whereKey($assignment->account_id)->lockForUpdate()->firstOrFail();

            // Only undo what this assignment did; if the account has since been
            // moved to someone else by hand, leave that alone.
            if ((int) $account->client_user_id === (int) $assignment->client_user_id) {
                $account->forceFill(['client_user_id' => $assignment->previous_client_user_id])->save();
            }

            $assignment->forceFill(['revoked_at' => now()])->save();

            return $account;
        });

        $member = $assignment->botUser;

        if ($member !== null) {
            $this->notifier->user($member, fn () => __('shahbot::bot.give_revoked', [
                'bot' => e($this->brand($assignment->bot_id)),
                'account' => e($account->display_label ?: $account->remote_username),
            ]));
        }
    }

    protected function brand(?int $botId): string
    {
        return (string) $this->context->run((int) $botId, fn () => app(BotSettings::class)->get('brand_name') ?: config('app.name'));
    }
}
