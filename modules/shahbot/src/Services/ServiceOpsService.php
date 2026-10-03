<?php

namespace Modules\ShahBot\Services;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Server;
use App\Models\User;
use App\Services\AccountRefundService;
use App\Services\AccountTransferService;
use App\Services\PortalLinkService;
use App\Services\SubscriptionFeedService;
use App\Services\WalletService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotRefundRequest;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotSettings;
use Modules\ShahBot\Telegram\Keyboard;

/**
 * What a customer can do to a service besides renewing it: hand it to another
 * user of the same bot, move it to another location (server) of its package,
 * or ask for their money back. All three run through the panel's own
 * services, so the accounting and the remote panels stay consistent.
 */
class ServiceOpsService
{
    public function __construct(
        protected BotSettings $settings,
        protected BotUserService $users,
        protected ShopService $shop,
        protected WalletService $wallets,
        protected BotNotifier $notifier,
    ) {}

    // ------------------------------------------------------------------
    // Transfer to another user
    // ------------------------------------------------------------------

    public function findRecipient(BotUser $from, string $input): BotUser
    {
        $input = trim(western_digits($input));
        $query = BotUser::query()->where('bot_id', $from->bot_id)->where('id', '!=', $from->id)->where('is_blocked', false);

        $recipient = preg_match('/^\d{4,}$/', $input)
            ? $query->where('telegram_id', (int) $input)->first()
            : $query->where('username', ltrim($input, '@'))->first();

        if ($recipient === null) {
            throw new InvalidArgumentException(__('shahbot::bot.transfer_no_user'));
        }

        return $recipient;
    }

    public function transfer(BotUser $from, Account $account, BotUser $to): Account
    {
        if (! $this->settings->bool('transfer_enabled')) {
            throw new InvalidArgumentException(__('shahbot::bot.op_disabled'));
        }

        $this->shop->assertOwnsAccount($from, $account);

        if ((int) $to->bot_id !== (int) $from->bot_id || $to->id === $from->id) {
            throw new InvalidArgumentException(__('shahbot::bot.transfer_no_user'));
        }

        $client = $this->users->client($to);

        DB::transaction(function () use ($account, $client): void {
            $account->forceFill(['client_user_id' => $client->id, 'auto_renew' => false])->save();
            // The giver must lose access: both the subscription link and the
            // portal link are reissued for the new owner.
            app(SubscriptionFeedService::class)->issue($account);
            app(PortalLinkService::class)->regenerate($account);
        });

        $name = e((string) ($account->display_label ?: $account->remote_username));
        $this->notifier->user($to, fn () => __('shahbot::bot.transfer_received', ['name' => $name, 'from' => e($from->displayName())]));

        return $account->fresh();
    }

    // ------------------------------------------------------------------
    // Change location
    // ------------------------------------------------------------------

    /**
     * Other active servers the account's package may use.
     *
     * @return Collection<int, Server>
     */
    public function locations(Account $account): Collection
    {
        $account->loadMissing('package.servers');

        return ($account->package?->servers ?? collect())
            ->filter(fn (Server $server) => $server->is_active && (int) $server->id !== (int) $account->server_id)
            ->values();
    }

    public function changeLocation(BotUser $user, Account $account, int $serverId): Account
    {
        if (! $this->settings->bool('location_enabled')) {
            throw new InvalidArgumentException(__('shahbot::bot.op_disabled'));
        }

        $this->shop->assertOwnsAccount($user, $account);
        $server = $this->locations($account)->first(fn (Server $s) => (int) $s->id === $serverId);

        if ($server === null) {
            throw new InvalidArgumentException(__('shahbot::bot.location_unavailable'));
        }

        // One move per service per day, so a customer cannot bounce between
        // servers and load every router with re-provisioning.
        $key = 'shahbot:location:'.$account->id;

        if (Cache::has($key)) {
            throw new InvalidArgumentException(__('shahbot::bot.location_once_a_day'));
        }

        $fee = number_format(max(0, (float) $this->settings->get('location_fee')), 2, '.', '');
        $client = $this->users->client($user);
        $owner = $this->users->owner($user);

        if ((float) $fee > 0 && (float) $this->users->balance($user) < (float) $fee) {
            throw new InvalidArgumentException(__('shahbot::bot.balance_low'));
        }

        $moved = DB::transaction(function () use ($account, $server, $owner, $client, $fee): Account {
            if ((float) $fee > 0) {
                $context = ['description' => 'Bot location change fee', 'related_account_id' => $account->id];
                $this->wallets->debit($client, $fee, TransactionType::Adjustment, array_merge($context, ['source_user_id' => $owner->id]));
                $this->wallets->credit($owner, $fee, TransactionType::Adjustment, array_merge($context, ['source_user_id' => $client->id]));
            }

            return app(AccountTransferService::class)->transfer($account, $server, $owner);
        });

        Cache::put($key, true, now()->addDay());

        return $moved;
    }

    // ------------------------------------------------------------------
    // Refund requests
    // ------------------------------------------------------------------

    public function requestRefund(BotUser $user, Account $account, string $reason): BotRefundRequest
    {
        if (! $this->settings->bool('refund_enabled')) {
            throw new InvalidArgumentException(__('shahbot::bot.op_disabled'));
        }

        $this->shop->assertOwnsAccount($user, $account);

        if ($account->refunded_at !== null) {
            throw new InvalidArgumentException(__('shahbot::bot.refund_done_already'));
        }

        if (BotRefundRequest::query()->where('account_id', $account->id)->where('status', BotRefundRequest::PENDING)->exists()) {
            throw new InvalidArgumentException(__('shahbot::bot.refund_pending'));
        }

        $request = BotRefundRequest::query()->create([
            'bot_user_id' => $user->id,
            'account_id' => $account->id,
            'reason' => mb_substr(trim($reason), 0, 1000),
            'status' => BotRefundRequest::PENDING,
        ]);

        $this->notifier->admins(__('shahbot::bot.admin_refund_request', [
            'id' => $request->id,
            'user' => e($user->displayName()),
            'tg' => $user->telegram_id,
            'name' => e((string) ($account->display_label ?: $account->remote_username)),
            'reason' => e((string) $request->reason),
        ]), Keyboard::inline([[
            Keyboard::button(__('shahbot::bot.btn_approve'), 'adm:rf:ok:'.$request->id),
            Keyboard::button(__('shahbot::bot.btn_reject'), 'adm:rf:no:'.$request->id),
        ]]));

        return $request;
    }

    /**
     * Refunds through the panel's refund (unused share back to the wallets,
     * the account disabled) and tells the customer what came back.
     */
    public function approveRefund(BotRefundRequest $request, string $reviewer, ?User $performer = null): BotRefundRequest
    {
        $claimed = BotRefundRequest::query()
            ->whereKey($request->id)
            ->where('status', BotRefundRequest::PENDING)
            ->update(['status' => 'processing']);

        if ($claimed === 0) {
            throw new InvalidArgumentException(__('shahbot::bot.refund_reviewed'));
        }

        try {
            $request->refresh();
            $performer ??= $this->users->owner($request->botUser);
            $result = app(AccountRefundService::class)->refund($request->account, $performer);
        } catch (\Throwable $e) {
            $request->update(['status' => BotRefundRequest::PENDING]);

            throw new InvalidArgumentException($e->getMessage(), previous: $e);
        }

        $amount = (string) ($result['refund_amount'] ?? '0');
        $request->update([
            'status' => BotRefundRequest::APPROVED,
            'amount' => $amount,
            'reviewed_by' => mb_substr($reviewer, 0, 128),
            'reviewed_at' => now(),
        ]);

        $this->notifier->user($request->botUser, fn () => __('shahbot::bot.refund_approved', [
            'name' => e((string) ($request->account->display_label ?: $request->account->remote_username)),
            'amount' => format_money($amount),
        ]));

        return $request->fresh();
    }

    public function rejectRefund(BotRefundRequest $request, string $reviewer): BotRefundRequest
    {
        $updated = BotRefundRequest::query()
            ->whereKey($request->id)
            ->where('status', BotRefundRequest::PENDING)
            ->update(['status' => BotRefundRequest::REJECTED, 'reviewed_by' => mb_substr($reviewer, 0, 128), 'reviewed_at' => now()]);

        if ($updated === 0) {
            throw new InvalidArgumentException(__('shahbot::bot.refund_reviewed'));
        }

        $this->notifier->user($request->botUser, fn () => __('shahbot::bot.refund_rejected', [
            'name' => e((string) ($request->account?->display_label ?: $request->account?->remote_username)),
        ]));

        return $request->fresh();
    }
}
