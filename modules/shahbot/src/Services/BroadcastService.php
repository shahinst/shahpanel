<?php

namespace Modules\ShahBot\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\ShahBot\Models\BotBroadcast;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotContext;
use Modules\ShahBot\Telegram\TelegramClient;

/**
 * Messages to every user, sent in small batches by `shahbot:broadcast` so
 * Telegram's ~30 messages/second limit is never hit and a long send survives
 * restarts (the cursor is the last user id handled).
 */
class BroadcastService
{
    public function __construct(
        protected TelegramClient $telegram,
        protected BotContext $context,
    ) {}

    public function audienceQuery(string $audience, int $botId = 0): Builder
    {
        $query = BotUser::query()->where('bot_id', $botId)->where('is_blocked', false)->where('bot_blocked_by_user', false);

        $withService = fn (Builder $q) => $q->whereNotNull('client_user_id')
            ->whereExists(fn ($s) => $s->selectRaw('1')->from('accounts')
                ->whereColumn('accounts.client_user_id', 'shahbot_users.client_user_id')
                ->whereNull('accounts.deleted_at'));

        return match ($audience) {
            'customers' => $withService($query),
            'no_service' => $query->where(fn (Builder $q) => $q->whereNull('client_user_id')
                ->orWhereNotExists(fn ($s) => $s->selectRaw('1')->from('accounts')
                    ->whereColumn('accounts.client_user_id', 'shahbot_users.client_user_id')
                    ->whereNull('accounts.deleted_at'))),
            default => $query,
        };
    }

    public function create(string $text, string $audience, string $author, int $botId = 0): BotBroadcast
    {
        $audience = in_array($audience, ['all', 'customers', 'no_service'], true) ? $audience : 'all';

        return BotBroadcast::query()->create([
            'bot_id' => $botId,
            'text' => $text,
            'audience' => $audience,
            'status' => BotBroadcast::QUEUED,
            'total' => $this->audienceQuery($audience, $botId)->count(),
            'created_by' => $author,
        ]);
    }

    /**
     * Sends up to $limit messages of the oldest unfinished broadcast.
     */
    public function runBatch(int $limit = 400): ?BotBroadcast
    {
        $broadcast = BotBroadcast::query()
            ->whereIn('status', [BotBroadcast::QUEUED, BotBroadcast::SENDING])
            ->oldest('id')
            ->first();

        if ($broadcast === null) {
            return null;
        }

        $broadcast->update(['status' => BotBroadcast::SENDING]);

        return $this->context->run((int) $broadcast->bot_id, fn () => $this->sendBatch($broadcast, $limit));
    }

    protected function sendBatch(BotBroadcast $broadcast, int $limit): BotBroadcast
    {
        $users = $this->audienceQuery($broadcast->audience, (int) $broadcast->bot_id)
            ->where('id', '>', $broadcast->cursor)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'telegram_id']);

        foreach ($users as $user) {
            if ($broadcast->fresh()?->status === BotBroadcast::CANCELLED) {
                return $broadcast->fresh();
            }

            $result = $this->telegram->sendMessage($user->telegram_id, $broadcast->text);

            if ($result['ok'] ?? false) {
                $broadcast->increment('sent');
            } else {
                $broadcast->increment('failed');

                if ($this->telegram->lastErrorCode === 403) {
                    BotUser::query()->whereKey($user->id)->update(['bot_blocked_by_user' => true]);
                }
            }

            $broadcast->forceFill(['cursor' => $user->id])->save();
            usleep(40_000);
        }

        if ($users->count() < $limit) {
            $broadcast->update(['status' => BotBroadcast::DONE, 'finished_at' => now()]);
        }

        return $broadcast->fresh();
    }
}
