<?php

namespace Modules\ShahBot\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\EndUserService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Modules\ShahBot\Models\BotUser;
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
    ) {}

    public function owner(): User
    {
        $owner = User::query()->find($this->settings->int('owner_user_id'));

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

        $user = BotUser::query()->firstOrNew(['telegram_id' => $telegramId]);
        $isNew = ! $user->exists;

        $user->fill([
            'username' => $from['username'] ?? null,
            'first_name' => mb_substr((string) ($from['first_name'] ?? ''), 0, 128) ?: null,
            'last_name' => mb_substr((string) ($from['last_name'] ?? ''), 0, 128) ?: null,
            'last_seen_at' => now(),
            'bot_blocked_by_user' => false,
        ]);

        if ($isNew && $startParam !== null && preg_match('/^ref_?(\d+)$/', $startParam, $m)) {
            $referrer = BotUser::query()->where('telegram_id', (int) $m[1])->first();

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

            $created = $this->endUsers->createAutoClientForAccount($this->owner(), 'TG '.$botUser->displayName());
            $client = $created['user'];
            $client->forceFill(['phone' => $botUser->phone ?: $client->phone])->save();

            $botUser->forceFill(['client_user_id' => $client->id])->save();
            $botUser->setRelation('client', $client);

            return $client;
        });
    }

    public function balance(BotUser $botUser): string
    {
        $wallet = $this->wallets->getOrCreateWallet($this->client($botUser))->fresh();

        return number_format((float) $wallet->balance, 2, '.', '');
    }
}
