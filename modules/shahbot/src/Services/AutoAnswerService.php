<?php

namespace Modules\ShahBot\Services;

use Modules\ShahBot\Models\BotTutorial;

/**
 * Finds the tutorial that already answers a support message, so the customer
 * can read it before the message becomes a ticket.
 *
 * Matching is by shared words, a title word counting double; short and
 * common words are ignored and Arabic letters folded into Persian ones, so
 * "كانفيگ" and "کانفیگ" meet. A tutorial needs at least two points and a
 * clear lead over the runner-up: a vague message goes straight to a person.
 */
class AutoAnswerService
{
    public const MIN_SCORE = 2;

    private const STOP = ['سلام', 'لطفا', 'ممنون', 'برای', 'این', 'اون', 'آن', 'که', 'چرا', 'چطور', 'چگونه', 'من', 'است', 'هست', 'نمی', 'میشه', 'دارم', 'the', 'and', 'for', 'with', 'how', 'why', 'not', 'please'];

    public function match(string $text): ?BotTutorial
    {
        $words = $this->words($text);

        if ($words === []) {
            return null;
        }

        $scores = BotTutorial::query()->where('is_active', true)->get()
            ->map(function (BotTutorial $tutorial) use ($words): array {
                $title = $this->words((string) $tutorial->title);
                $body = $this->words((string) $tutorial->body);
                $score = 2 * count(array_intersect($words, $title)) + count(array_intersect($words, array_diff($body, $title)));

                return ['tutorial' => $tutorial, 'score' => $score];
            })
            ->sortByDesc('score')
            ->values();

        $best = $scores->get(0);
        $runnerUp = $scores->get(1)['score'] ?? 0;

        return $best !== null && $best['score'] >= self::MIN_SCORE && $best['score'] > $runnerUp ? $best['tutorial'] : null;
    }

    /**
     * @return list<string>
     */
    public function words(string $text): array
    {
        $text = mb_strtolower(strtr(western_digits($text), ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', '‌' => ' ']));
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, self::STOP, true))));
    }
}
