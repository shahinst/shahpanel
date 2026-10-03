<?php

namespace Modules\ShahBot\Support;

use Illuminate\Support\Facades\App;
use Modules\ShahBot\Models\BotUser;

/**
 * The bot's languages. Each user has their own (chosen in the bot, or taken
 * from their Telegram app on first contact); anything the bot says to them,
 * including messages sent later from the panel or a scheduled job, is built
 * in that language.
 */
class BotLocale
{
    public const SUPPORTED = ['fa', 'en', 'ru', 'zh'];

    public const NAMES = ['fa' => '🇮🇷 فارسی', 'en' => '🇬🇧 English', 'ru' => '🇷🇺 Русский', 'zh' => '🇨🇳 中文'];

    public function __construct(protected BotSettings $settings) {}

    /**
     * @return list<string>
     */
    public function enabled(): array
    {
        $list = array_values(array_intersect(self::SUPPORTED, $this->settings->lines('languages')));

        return $list !== [] ? $list : ['fa'];
    }

    public function default(): string
    {
        $default = $this->settings->get('default_language');

        return in_array($default, $this->enabled(), true) ? $default : $this->enabled()[0];
    }

    public function for(?BotUser $user): string
    {
        $language = (string) ($user?->language ?? '');

        return in_array($language, $this->enabled(), true) ? $language : $this->default();
    }

    /**
     * The language to give a new user: their Telegram app's, when the bot
     * speaks it, otherwise the default.
     */
    public function guess(?string $telegramCode): string
    {
        $code = strtolower(substr((string) $telegramCode, 0, 2));

        return in_array($code, $this->enabled(), true) ? $code : $this->default();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(?BotUser $user, callable $callback): mixed
    {
        $previous = App::getLocale();
        App::setLocale($this->for($user));
        app(BotTexts::class)->apply();

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }
}
