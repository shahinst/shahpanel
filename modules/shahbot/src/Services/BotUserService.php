<?php

namespace Modules\ShahBot\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\EndUserService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotContext;
use Modules\ShahBot\Support\BotLocale;
use Modules\ShahBot\Support\BotSettings;
use RuntimeException;

/**
 * Telegram users of the bot. Each one is backed by an ordinary panel client
 * under the bot's owner (an agent or seller), so purchases, wallets, invoices
 * and commissions run through exactly the same code as the client portal.
 */
class BotUserService
{
    public function __construct(
        protected BotSettings $settings,
        protected EndUserService $endUsers,
        protected WalletService $wallets,
        protected BotContext $context,
    ) {}

    /**
     * The sales owner: of the given user's bot when one is given (an agent's
     * bot sells for that agent), otherwise of the bot in context.
     */
    public function owner(?BotUser $for = null): User
    {
        $ownerId = $for !== null && (int) $for->bot_id > 0
            ? (int) BotInstance::query()->whereKey($for->bot_id)->value('owner_user_id')
            : $this->settings->int('owner_user_id');

        $owner = User::query()->find($ownerId);

        if ($owner === null || ! in_array($owner->role, [UserRole::Agent, UserRole::Seller], true)) {
            throw new RuntimeException(__('shahbot::bot.err_owner_missing'));
        }

        return $owner;
    }

    /**
     * @param  array{id: int, username?: string, first_name?: string, last_name?: string}  $from
     */
    public function register(array $from, ?string $startParam = null): BotUser
    {
        $telegramId = (int) $from['id'];

        $botId = $this->context->botId();
        $user = BotUser::query()->firstOrNew(['bot_id' => $botId, 'telegram_id' => $telegramId]);
        $isNew = ! $user->exists;

        $user->fill([
            'username' => $from['username'] ?? null,
            'first_name' => mb_substr((string) ($from['first_name'] ?? ''), 0, 128) ?: null,
            'last_name' => mb_substr((string) ($from['last_name'] ?? ''), 0, 128) ?: null,
            'last_seen_at' => now(),
            'bot_blocked_by_user' => false,
        ]);

        if ($isNew) {
            $user->language = app(BotLocale::class)->guess($from['language_code'] ?? null);
        }

        if ($isNew && $startParam !== null && preg_match('/^ref_?(\d+)$/', $startParam, $m)) {
            $referrer = BotUser::query()->where('bot_id', $botId)->where('telegram_id', (int) $m[1])->first();

            if ($referrer !== null && (int) $referrer->telegram_id !== $telegramId) {
                $user->referrer_id = $referrer->id;
            }
        }

        $user->save();
        $user->wasJustCreated = $isNew;

        return $user;
    }

    /**
     * The panel client behind a bot user, created on first need.
     */
    public function client(BotUser $botUser): User
    {
        if ($botUser->client_user_id !== null) {
            $client = $botUser->client;

            if ($client !== null) {
                return $client;
            }
        }

        return DB::transaction(function () use ($botUser): User {
            $locked = BotUser::query()->whereKey($botUser->id)->lockForUpdate()->first();

            if ($locked?->client_user_id !== null && ($existing = User::query()->find($locked->client_user_id)) !== null) {
                $botUser->setRelation('client', $existing);
                $botUser->client_user_id = $existing->id;

                return $existing;
            }

            $created = $this->endUsers->createAutoClientForAccount($this->owner($botUser), 'TG '.$botUser->displayName());
            $client = $created['user'];
            $client->forceFill(['phone' => $botUser->phone ?: $client->phone])->save();

            $botUser->forceFill(['client_user_id' => $client->id])->save();
            $botUser->setRelation('client', $client);

            return $client;
        });
    }

    /**
     * The panel account whose wallet this Telegram user actually holds.
     *
     * Every bot user is backed by an auto-made customer ("TG name"), and the
     * wallet menu used to show that customer's balance -- for an agent or
     * seller too, so the figure in their bot never matched the one in their
     * panel. A reseller made through an agency request holds their seller
     * account's wallet, and the owner of an agent bot, writing from one of
     * that bot's admin chats, holds their own. Everyone else is a customer.
     */
    public function walletHolder(BotUser $botUser, bool $isAdminChat): User
    {
        if ($botUser->reseller_user_id !== null && ($reseller = $botUser->reseller) !== null) {
            return $reseller;
        }

        if ($isAdminChat && (int) $botUser->bot_id > 0) {
            return $this->owner($botUser);
        }

        return $this->client($botUser);
    }

    public function walletBalance(User $holder): string
    {
        $wallet = $this->wallets->getOrCreateWallet($holder)->fresh();

        return number_format((float) $wallet->balance, 2, '.', '');
    }

    public function balance(BotUser $botUser): string
    {
        $wallet = $this->wallets->getOrCreateWallet($this->client($botUser))->fresh();

        return number_format((float) $wallet->balance, 2, '.', '');
    }
}
