<?php

namespace Modules\ShahBot\Support;

use Modules\ShahBot\Models\BotInstance;

/**
 * Which bot the current work belongs to: null for the main bot, otherwise an
 * agent's bot. BotSettings layers that bot's own values over the main ones,
 * so every service that reads a setting (the token above all) follows it.
 */
class BotContext
{
    protected ?BotInstance $bot = null;

    public function bot(): ?BotInstance
    {
        return $this->bot;
    }

    public function botId(): int
    {
        return (int) ($this->bot?->id ?? 0);
    }

    public function set(?BotInstance $bot): void
    {
        $this->bot = $bot;
    }

    /**
     * Runs $callback inside the given bot (an id, 0 for the main bot) and
     * restores the previous one afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(int|BotInstance|null $bot, callable $callback): mixed
    {
        $previous = $this->bot;

        if (is_int($bot)) {
            $bot = $bot > 0 ? BotInstance::query()->find($bot) : null;
        }

        $this->bot = $bot;

        try {
            return $callback();
        } finally {
            $this->bot = $previous;
        }
    }
}
