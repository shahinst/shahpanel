<?php

namespace Modules\ShahBot\Support;

use Throwable;

/**
 * Admin-edited bot texts. The stock wording lives in resources/lang/fa/bot.php;
 * edits are stored as one JSON setting and laid over the loaded translation
 * group, so every __('shahbot::bot.*') call — and the matching of menu button
 * presses — picks them up without any other code knowing.
 */
class BotTexts
{
    protected static bool $applied = false;

    public function __construct(protected BotSettings $settings) {}

    /**
     * @return array<string, string>
     */
    public function defaults(): array
    {
        $file = dirname(__DIR__, 2).'/resources/lang/fa/bot.php';

        return is_file($file) ? array_filter((array) require $file, 'is_string') : [];
    }

    /**
     * @return array<string, string>
     */
    public function overrides(): array
    {
        $raw = json_decode($this->settings->main('texts') ?: '[]', true);

        return is_array($raw) ? array_filter($raw, fn ($v, $k) => is_string($v) && $v !== '' && is_string($k), ARRAY_FILTER_USE_BOTH) : [];
    }

    /**
     * Saves only what differs from the stock text.
     *
     * @param  array<string, ?string>  $input
     */
    public function save(array $input): void
    {
        $defaults = $this->defaults();
        $keep = [];

        foreach ($input as $key => $value) {
            $value = str_replace("\r\n", "\n", (string) $value);

            if (array_key_exists($key, $defaults) && trim($value) !== '' && $value !== $defaults[$key]) {
                $keep[$key] = $value;
            }
        }

        $this->settings->set(['texts' => json_encode($keep, JSON_UNESCAPED_UNICODE)]);
        static::$applied = false;
        $this->apply(true);
    }

    public function apply(bool $force = false): void
    {
        if (static::$applied && ! $force) {
            return;
        }

        try {
            $translator = app('translator');
            // Load the stock group first: adding lines to a group marks it as
            // loaded, and the rest of the file would then never be read.
            $translator->load('shahbot', 'bot', 'fa');

            $lines = [];
            foreach ($this->overrides() as $key => $value) {
                $lines['bot.'.$key] = $value;
            }

            if ($force) {
                foreach ($this->defaults() as $key => $value) {
                    $lines['bot.'.$key] ??= $value;
                }
            }

            $translator->addLines($lines, 'fa', 'shahbot');
            static::$applied = true;
        } catch (Throwable) {
            // Before the module's tables exist: the stock texts are fine.
        }
    }
}
